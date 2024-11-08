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
                    if (empty($course->records)) { // If a course has no grade records, remove it from the report.
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
        global $DB;

        $modinfo = get_fast_modinfo($courseid);

        $dateformat = get_config('report_feedback_tracker', 'dateformat');
        $assessmenttypes = helper::get_assessment_types($courseid);

        // Get all grade items for the course.
        $gradeitems = grade_item::fetch_all(['courseid' => $courseid]);

        $data = new stdClass();
        $data->courseid = $courseid;
        $data->staffdata = true;
        $data->canedit = true;
        $data->outputedit = true;
        $data->records = [];

        $data->dropdownstudents = helper::get_course_students($courseid);

        // Create records for manual grade items and supported course modules.
        foreach ($gradeitems as $gradeitem) {

            // If it is a 'manual' grade item there is no course module.
            if ($gradeitem->itemtype === 'manual') {
                $record = new stdClass();
                $record->name = $gradeitem->itemname;
                $record->manual = true;
                $record->feedbackduedateraw = 9999999999; // Needed for sorting. Make sure they are listed last.

                $data->records[] = $record;
                continue;
            }

            // Skip any gradeitem without a module or a with module that is not suported.
            if (!$gradeitem->itemmodule || !helper::is_supported_module($gradeitem->itemmodule)) {
                continue;
            }

            // Skip if no module record can be found.
            if (!$record = admin::get_module_record($gradeitem, $modinfo, $assessmenttypes)) {
                continue;
            }

            // If the module is a Turnitin module create a record for each part of it.
            if ($gradeitem->itemmodule === 'turnitintooltwo') {
                $tttparts = helper::get_turnitin_parts($gradeitem);

                foreach ($tttparts as $tttpart) {
                    $record->name = $gradeitem->itemname . " - " . $tttpart->partname;
                    $record->partid = $tttpart->id;

                    $duedate = $tttpart->dtdue;
                    // The raw date is needed for sorting.
                    $record->feedbackduedateraw = $duedate ? helper::calculate_feedback_duedate($courseid, $duedate) : 9999999999;
                    $record->feedbackduedate = $duedate ? date($dateformat, $record->feedbackduedateraw) : false;

                    // Get additional information for the record.
                    $params = [
                        'gradeitem' => $gradeitem->id,
                        'partid' => $record->partid,
                    ];
                    if ($additionaldata = $DB->get_record('report_feedback_tracker', $params)) {
                        $record->method = $additionaldata->method;
                        $record->contact = $additionaldata->responsibility;
                        $record->generalfeedback = $additionaldata->generalfeedback;
                        if ($additionaldata->feedbackduedate) {
                            $record->feedbackduedateraw = $additionaldata->feedbackduedate;
                            $record->feedbackduedate = date($dateformat, $record->feedbackduedateraw);
                        }
                        $record->additionaldata = $record->generalfeedback || $record->method || $record->contact;
                        $record->hiddenfromreport = $additionaldata->hidden;
                    }

                    $data->records[] = clone $record;
                }
            } else {
                // Get additional information for the record.
                $params = [
                    'gradeitem' => $gradeitem->id,
                ];
                if ($additionaldata = $DB->get_record('report_feedback_tracker', $params)) {
                    $record->method = $additionaldata->method;
                    $record->contact = $additionaldata->responsibility;
                    $record->generalfeedback = $additionaldata->generalfeedback;
                    if ($additionaldata->feedbackduedate) {
                        $record->feedbackduedateraw = $additionaldata->feedbackduedate;
                        $record->feedbackduedate = date($dateformat, $record->feedbackduedateraw);
                    }
                    $record->additionaldata = $record->generalfeedback || $record->method || $record->contact;
                    $record->hiddenfromreport = $additionaldata->hidden;
                }

                $data->records[] = $record;
            }
        }

        // Sort the data records by feedback due date.
        usort($data->records, function($a, $b) {
            return strcmp($a->feedbackduedateraw, $b->feedbackduedateraw);
        });

        return $this->output->render_from_template('report_feedback_tracker/course/course', $data);
    }

}
