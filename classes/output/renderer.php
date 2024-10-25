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
use plugin_renderer_base;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;
use report_feedback_tracker\local\user;
use stdClass;

/**
 * Renderer class for feedback tracker report table.
 */
class renderer extends plugin_renderer_base {

    /**
     * Render the user table.
     *
     * @param int $userid
     * @param int $courseid optional course id to limit output.
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_user_data($userid, $courseid = 0): string {
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
            $feedbacktrackerdata->dropdownstudents = helper::get_students_for_dropdown($courseid, $userid);

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
     * Render the user table.
     *
     * @param int $userid
     * @param int $courseid optional course id to limit output.
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_userview_data($userid, $courseid = 0): string {
        // Get the table data.
        $feedbacktrackerdata = user::get_feedback_tracker_user_data($userid, $courseid);

        // If no course ID is provided, show assessments from all courses.
        // While there are more than one courses, remove the ones without assessments.
        // If there is only one course without assessments show it nevertheless.
        if (count($feedbacktrackerdata->courses) !== 1 && $courseid === 0) {
            $coursesremoved = false;
            foreach ($feedbacktrackerdata->courses as $key => $course) {
                if (empty($course->records)) {
                    unset($feedbacktrackerdata->courses[$key]);
                    $coursesremoved = true;
                }
            }
            // If we removed any courses, reindex the array.
            if ($coursesremoved) {
                $feedbacktrackerdata->courses = array_values($feedbacktrackerdata->courses);
            }
        }

        // Render the table data.
        $feedbacktrackerdata->viewasstudent = true;
        return $this->output->render_from_template('report_feedback_tracker/course/course',
            $feedbacktrackerdata);
    }

    /**
     * Render the wrapper containing the table for a course admin.
     *
     * @param int $courseid
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_admin_wrapper($courseid): string {
        // Get the table data.
        $feedbacktrackerdata = admin::get_feedback_tracker_admin_data($courseid);
        $feedbacktrackerdata->courseid = $courseid;
        // Render the table data.
        if ($feedbacktrackerdata->editmode) {
            return $this->output->render_from_template('report_feedback_tracker/adminedittable', $feedbacktrackerdata);
        }
        return $this->output->render_from_template('report_feedback_tracker/adminwrapper', $feedbacktrackerdata);
    }

    public function render_feedback_tracker_admin(int $courseid): string {
        global $DB, $OUTPUT;

        $data = new stdClass();

        $context = context_course::instance($courseid);
        $modinfo = get_fast_modinfo($courseid);

        // Get all grade items for the course.
        $gradeitems = grade_item::fetch_all(['courseid' => $courseid]);

        // Get all course modules for the course.
        $coursemodules = get_fast_modinfo($courseid);

        $data->courseid = $courseid;
        $data->staffdata = true;
        $data->canedit = true;
        $data->outputedit = true;
        $data->records = [];

        $dateformat = get_config('report_feedback_tracker', 'dateformat');
        $assessmenttypes = helper::get_assessment_types($courseid);
        $users = get_enrolled_users($context);

        $data->dropdownstudents = helper::get_students_for_dropdown($courseid);

        // Get the course module for each grade item and add any manual grade items.
        foreach ($gradeitems as $gradeitem) {

            // If it is a 'manual' grade item there is no course module.
            if ($gradeitem->itemtype === 'manual') {
                // Add a manual record to the data.
                $record = new stdClass();
                $record->name = $gradeitem->itemname;
                $record->manual = true;
                $record->feedbackduedateraw = 9999999999; // Needed for sorting. Make sure they are listed last.

                $data->records[] = $record;
                continue;
            }

            // Skip any gradeitem without a module or a with module that is not suported.
            if (!$gradeitem->itemmodule || !helper::module_is_supported_new($gradeitem->itemmodule)) {
                continue;
            }


            // SQL query to get the course module ID from a grade item.
            $sql = "
                    SELECT cm.id AS cmid
                    FROM {course_modules} cm
                    JOIN {modules} m ON cm.module = m.id
                    JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
                    WHERE gi.id = :gradeitemid
                ";

            // Execute the query.
            $cm = $DB->get_record_sql($sql, ['gradeitemid' => $gradeitem->id]);

            if ($cm) {
                // Get the module.
                $cmid = $cm->cmid;
                $module = $modinfo->get_cm($cmid);

                // Build the record.
                $record = new stdClass();
                $record->name = $gradeitem->itemname; // The grade item name has more details.
                $record->moduletypeiconurl = $module->get_icon_url()->out(false);

                $record->hiddenfromstudents = !$module->visible;
                $record->hiddenfromreport = false;

                $record->cmid = $module->id;
                $record->partid = false;

                // Assessment type.
                $assessmenttype = helper::get_assessment_type_new($record, $assessmenttypes);
                $record->formative = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_FORMATIVE ? true : false;
                $record->summative = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_SUMMATIVE ? true : false;
                $record->dummy = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_DUMMY ? true : false;
                $record->notset = !$record->formative && !$record->summative && !$record->dummy;

                $record->modname = $module->modname;

                $duedate = helper::get_duedate($module);
                $record->duedate = $duedate ? date($dateformat, $duedate) : false;
                // The raw date is needed for sorting.
                $record->feedbackduedateraw = $duedate ? helper::get_feedbackduedate_new($courseid, $duedate) : 9999999999;
                $record->feedbackduedate = $record->feedbackduedateraw ? date($dateformat, $record->feedbackduedateraw) : false;
                $record->markoverdue = false;

                $record->overrides = helper::get_overrides($module);
                $record->submissions = count(helper::get_submissions($module));
                $grades = helper::get_grade_grades($gradeitem);
                $record->requiredfeedbacks = ($record->submissions - $grades) < 0 ? 0 :
                    $record->submissions - $grades;
                $record->feedbackpercentage = round($record->submissions ? $grades/$record->submissions * 100 : 0, 2);
                $record->requiremarkingcount = false;
                $record->url = $module->get_url();

                $data->records[] = $record;
            } else { // There is no course module for this grade item.
                $cmid = 0;
            }
        }

        // Sort the data records by feedback due date.
        usort($data->records, function($a, $b) {
            return strcmp($a->feedbackduedateraw, $b->feedbackduedateraw);
        });

        return $this->output->render_from_template('report_feedback_tracker/course/course', $data);
    }

    /**
     * Render the feedback tracker admin table.
     *
     * @param int $courseid
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_admin_table($courseid): string {
        // Get the table data.
        $feedbacktrackerdata = admin::get_feedback_tracker_admin_data($courseid);
        // Render the table data.
        return $this->output->render_from_template('report_feedback_tracker/admintable', $feedbacktrackerdata);
    }

}
