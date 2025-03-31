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
use local_assess_type\assess_type;
use moodle_url;
use plugin_renderer_base;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;
use report_feedback_tracker\local\site;
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
     */
    public function render_feedback_tracker_user_report($userid, $courseid): string {

        // Get the table data.
        $feedbacktrackerdata = user::get_feedback_tracker_user_data($userid, $courseid);

        if ($courseid === SITEID) { // Render all courses for a student.
            return $this->render_all_student_courses($feedbacktrackerdata);
        } else { // Render a student view of a single course as an editor.
            $context = context_course::instance($courseid);
            $feedbacktrackerdata->courseid = $courseid;
            $feedbacktrackerdata->canedit = has_capability('moodle/grade:edit', $context);
            $feedbacktrackerdata->viewasstudent = true;
            $feedbacktrackerdata->dropdownstudents = helper::get_course_students($courseid, $userid);

            return $this->output->render_from_template('report_feedback_tracker/course/course',
                $feedbacktrackerdata);
        }
    }

    /**
     * Render all courses for a student.
     *
     * @param stdClass $feedbacktrackerdata
     * @return string
     */
    private function render_all_student_courses(stdClass $feedbacktrackerdata): string {
        $feedbacktrackerdata->canedit = false;
        // While there are more than one courses, remove the ones without assessments.
        // If there is only one course without assessments show it nevertheless.
        if ($feedbacktrackerdata->courses) {
            $coursesremoved = false;
            foreach ($feedbacktrackerdata->courses as $key => $course) {
                // If there is only one course (left) do not remove it.
                if (count($feedbacktrackerdata->courses) === 1) {
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

    /**
     * Render the course report.
     *
     * @param int $courseid
     * @return string
     */
    public function render_feedback_tracker_course_report(int $courseid): string {
        $modinfo = get_fast_modinfo($courseid);

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');

        // Get all grade items for the course.
        $gradeitems = grade_item::fetch_all(['courseid' => $courseid]) ?: [];

        $data = new stdClass();
        $data->courseid = $courseid;
        $data->staffdata = true;
        $data->canedit = true;
        $data->outputedit = true;
        $data->items = [];

        $assesstypes = helper::get_assessment_types($courseid);
        $data->dropdownstudents = helper::get_course_students($courseid);

        // If present create records for manual grade items and supported course modules.
        foreach ($gradeitems as $gradeitem) {
            if (($gradeitem->itemtype === 'mod') &&
                    helper::is_supported_module($gradeitem->itemmodule) &&
                    $item = admin::get_module_data($modinfo, $gradeitem)) {
                if ($gradeitem->itemmodule === 'turnitintooltwo') {
                    // Add separate data for Turnitin parts.
                    helper::add_ttt_data($data, $gradeitem, $item, $assesstypes);
                } else {
                    $assesstype = helper::get_assesstype($item->gradeitemid, $item->cmid, $assesstypes);
                    helper::add_assesstype($item, $assesstype);
                    helper::add_additional_data($item);

                    $data->items[] = $item;
                }
            } else if ($gradeitem->itemtype === 'manual') {
                $item = new stdClass();
                $item->name = $gradeitem->itemname;
                $item->gradeitemid = $gradeitem->id;
                $item->cmid = 0;
                $item->partid = 0;
                $item->manual = true;
                $item->feedbackduedateraw = 9999999999; // Needed for sorting. Make sure they are listed last.
                // Add a URL pointing to the gradebook item in single view.
                $item->url = new moodle_url('/grade/report/singleview/index.php', [
                    'id' => $gradeitem->courseid,
                    'item' => 'grade',
                    'itemid' => $gradeitem->id,
                    'gpr_type' => 'report',
                    'gpr_plugin' => 'grader',
                    'gpr_courseid' => $gradeitem->courseid,
                ]);

                $assesstype = helper::get_assesstype($item->gradeitemid, $item->cmid, $assesstypes);
                helper::add_assesstype($item, $assesstype);
                helper::add_additional_data($item);

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
     * Render the site report.
     *
     * @return string
     */
    public function render_feedback_tracker_site_report(): string {
        // Get the table data.
        $data = site::get_feedback_tracker_site_data();
        // And render it.
        return $this->output->render_from_template('report_feedback_tracker/site/site', $data);
    }

}
