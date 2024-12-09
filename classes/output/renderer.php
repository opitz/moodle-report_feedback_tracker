<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The renderer.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_feedback_tracker\output;

use context_course;
use grade_item;
use plugin_renderer_base;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;
use report_feedback_tracker\local\user;
use stdClass;

/**
 * Renderer class for feedback tracker report.
 */
class renderer extends plugin_renderer_base {

    /**
     * Render the user report.
     *
     * @param int $userid
     * @param int $courseid optional course id to limit output.
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_user_report($userid, $courseid = 0): string {
        global $USER;

        // Course ID 1 is not a standard Moodle course and is excluded.
        if ($courseid < 2) {
            $courseid = 0;
        }
        // Get the table data.
        $feedbacktrackerdata = user::get_feedback_tracker_user_data($userid, $courseid);

        if ($courseid) { // Render a student view of single course as an editor.
            $context = context_course::instance($courseid);
            $feedbacktrackerdata->courseid = $courseid;
            $feedbacktrackerdata->canedit = has_capability('moodle/grade:edit', $context);
            $feedbacktrackerdata->viewasstudent = true;
            $feedbacktrackerdata->dropdownstudents = helper::get_course_students($courseid, $userid);

            return $this->output->render_from_template('report_feedback_tracker/course/course',
                $feedbacktrackerdata);
        } else { // Render all courses for a student.
            $feedbacktrackerdata->canedit = false;
            // While there are more than one courses, remove the ones without assessments.
            // If there is only one course without assessments show it nevertheless.
            if ($feedbacktrackerdata->courses) {
                $coursesremoved = false;
                foreach ($feedbacktrackerdata->courses as $key => $course) {
                    // If there is only one course (left) do not remove it.
                    if (count($feedbacktrackerdata->courses) < 2) {
                        break;
                    }
                    if (empty($course->items)) { // If a course has no grade records, remove it from the report.
                        unset($feedbacktrackerdata->courses[$key]);
                        $coursesremoved = true;
                    }
                }
                // If any courses have been removed, re-index the array.
                if ($coursesremoved) {
                    $feedbacktrackerdata->courses = array_values($feedbacktrackerdata->courses);
                }
            }
            return $this->output->render_from_template('report_feedback_tracker/user/courses', $feedbacktrackerdata);
        }
    }

    /**
     * Render the course report.
     *
     * @param int $courseid
     * @return string
     */
    public function render_feedback_tracker_course_report(int $courseid): string {
        global $CFG, $USER;

        $modinfo = get_fast_modinfo($courseid);

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');
        $assessmenttypes = helper::get_assessment_types($courseid);

        // Get all grade items for the course.
        $gradeitems = grade_item::fetch_all(['courseid' => $courseid]);

        $data = new stdClass();
        $data->courseid = $courseid;
        $data->staffdata = true;
        $data->canedit = true;
        $data->outputedit = true;
        $data->items = [];

        $data->dropdownstudents = helper::get_course_students($courseid);

        // Create records for manual grade items and supported course modules.
        foreach ($gradeitems as $gradeitem) {
            if (($gradeitem->itemtype === 'mod') &&
                helper::is_supported_module($gradeitem->itemmodule) &&
                $item = admin::get_module_data($gradeitem, $modinfo, $assessmenttypes)) {
                if ($gradeitem->itemmodule === 'turnitintooltwo') {
                    $tttparts = helper::get_turnitin_parts($gradeitem);

                    foreach ($tttparts as $tttpart) {
                        // Each part of a turnitintooltwo assessment starts as a clone of the module
                        // and adds data related to each part.
                        $tttitem = clone $item;
                        $tttitem->name = $gradeitem->itemname . " - " . $tttpart->partname;
                        $tttitem->partid = $tttpart->id;

                        // Get the due date for each part.
                        $duedate = $tttpart->dtdue;
                        // The feedback due date timestamp is needed for sorting.
                        if (!$duedate) {
                            $tttitem->feedbackduedateraw = 9999999999;
                            $tttitem->feedbackduedate = false;
                            $tttitem->duedate = false;
                        } else {
                            $tttitem->feedbackduedateraw = helper::calculate_feedback_duedate($courseid, $duedate);
                            $tttitem->feedbackduedate = userdate($tttitem->feedbackduedateraw, $dateformat);
                            $tttitem->duedate = userdate($duedate, $dateformat);
                        }

                        // Get additional information for each part record.
                        self::add_additional_data($tttitem);

                        $data->items[] = $tttitem;
                    }
                } else {
                    // Get additional information for the record.
                    self::add_additional_data($item);

                    $data->items[] = $item;
                }
            } else if ($gradeitem->itemtype === 'manual') {
                $item = new stdClass();
                $item->name = $gradeitem->itemname;
                $item->gradeitemid = $gradeitem->id;
                $item->partid = false;
                $item->manual = true;
                $item->feedbackduedateraw = 9999999999; // Needed for sorting. Make sure they are listed last.
                // Add a URL pointing to the gradebook item in single view.
                $item->url = "$CFG->wwwroot/grade/report/singleview/index.php?id=$gradeitem->courseid&" .
                    "item=grade&itemid=$gradeitem->id&gpr_type=report&gpr_plugin=grader&gpr_courseid=$gradeitem->courseid";
                // Get additional information for each part record.
                self::add_additional_data($item);

                $data->items[] = $item;
            }
        }

        // Sort the data records by feedback due date.
        usort($data->items, function($a, $b) {
            return strcmp($a->feedbackduedateraw, $b->feedbackduedateraw);
        });

        return $this->output->render_from_template('report_feedback_tracker/course/course', $data);
    }

    /**
     * Add additional data for the grade item where available.
     *
     * @param stdClass $data
     * @return void
     */
    private static function add_additional_data(stdClass $data): void {
        global $DB;

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');
        $params = ['gradeitem' => $data->gradeitemid];
        if ($data->partid) {
            $params['partid'] = $data->partid;
        }

        // There should be only one record - make sure nevertheless...
        if ($record = $DB->get_record('report_feedback_tracker', $params, '*', IGNORE_MULTIPLE)) {
            $data->method = $record->method;
            $data->contact = $record->responsibility;
            $data->generalfeedback = $record->generalfeedback;

            if ($record->feedbackduedate) {
                $data->customfeedbackduedate = date('Y-m-d', $record->feedbackduedate);
                $data->feedbackduedateraw = $record->feedbackduedate;
                $data->feedbackduedate = userdate($record->feedbackduedate, $dateformat);

                // Get a custom feedback due date reason entry for the grade item where available.
                $data->feedbackduedatereason = self::get_reason($data->gradeitemid, $data->feedbackduedate);
            }

            // Check if there is additional data to show.
            if ($data->generalfeedback || $data->method || $data->contact) {
                $data->additionaldata = true;
            }

            if ($record->gfdate) {
                $data->customfeedbackreleaseddate = date('Y-m-d', $record->gfdate);
            }

            $data->hiddenfromreport = (isset($data->hiddenfromreport) && $data->hiddenfromreport) || $record->hidden;
        }
    }

    /**
     * Return the current reason for a custom feedback due date or false.
     *
     * @param int $gradeitemid
     * @param string $feedbackduedate
     * @return string
     */
    private static function get_reason(int $gradeitemid, string $feedbackduedate): string {
        global $DB;

        $params = ['gradeitem' => $gradeitemid, 'feedbackduedate' => strtotime($feedbackduedate)];
        $record = $DB->get_record('report_feedback_tracker_duedates', $params, '*', IGNORE_MULTIPLE);
        if (isset($record->reason)) {
            return $record->reason;
        }

        return "";
    }

}
