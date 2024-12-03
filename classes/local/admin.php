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

namespace report_feedback_tracker\local;
use coding_exception;
use course_modinfo;
use dml_exception;
use grade_item;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;
use cm_info;

/**
 * This file contains the admin functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin {
    /**
     * Get a course module to the grade item where available and return a record for it.
     *
     * @param grade_item $gradeitem
     * @param course_modinfo $modinfo
     * @param array $assessmenttypes
     * @return false|stdClass
     */
    public static function get_module_data(
        grade_item $gradeitem,
        course_modinfo $modinfo,
        array $assessmenttypes
    ): false|stdClass {

        if (!$cm = self::get_cm_from_gradeitem($gradeitem)) {
            return false;
        }

        $dateformat = get_config('report_feedback_tracker', 'dateformat');

        // Get the module.
        $cmid = $cm->cmid;
        $module = $modinfo->get_cm($cmid);

        // Build the module data.
        $data = new stdClass();
        $data->gradeitemid = $gradeitem->id;
        $data->name = $gradeitem->itemname; // The grade item name has more details.
        $data->moduletypeiconurl = $module->get_icon_url()->out(false);

        $data->cmid = $module->id;
        $data->partid = false;

        // Assessment type.
        $assessmenttype = helper::get_assessment_type($data, $assessmenttypes);
        $data->assessmenttype = $assessmenttype['type'];
        $data->selectedassessmenttypelabel = helper::get_selected_assess_type_label($data->assessmenttype);
        $data->locked = $assessmenttype['locked'];
        $data->formative = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_FORMATIVE;
        $data->summative = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_SUMMATIVE;
        $data->dummy = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_DUMMY;
        $data->notset = !$data->formative && !$data->summative && !$data->dummy;

        // Hiding attributes.
        $data->hiddenfromstudents = !$module->visible;
        $data->hiddenfromreport = $data->dummy;

        $data->hiddendisabled = true;
        $data->assesstypes = helper::get_assess_types(isset($data->assessmenttype) ? $data->assessmenttype : null);

        $data->modname = $module->modname;

        // Dates.
        $duedate = helper::get_duedate($module);
        $data->duedate = $duedate ? date($dateformat, $duedate) : false;
        // The raw date is needed for sorting.
        $data->feedbackduedateraw = $duedate ? helper::calculate_feedback_duedate($gradeitem->courseid, $duedate) : 9999999999;
        $data->feedbackduedate = $data->duedate ? date($dateformat, $data->feedbackduedateraw) : false;
        $data->markoverdue = false;

        // Student data.
        $overrides = helper::get_overrides($module);
        if ($overrides === 1) {
            $data->overrides = get_string('users:extension', 'report_feedback_tracker');
        } else if ($overrides > 1) {
            $data->overrides = get_string('users:extensions', 'report_feedback_tracker', $overrides);
        }
        $data->overridesurl = helper::get_overrides_url($module);
        $data->submissions = helper::count_submissions($module);

        // Grades and markings.
        $data->requiredfeedbacks = helper::count_missing_grades($gradeitem, $module, $data->submissions);
        $data->feedbackpercentage = $data->submissions ?
            round(($data->submissions - $data->requiredfeedbacks) / $data->submissions * 100, 0) : 0;
        $data->url = $module->get_url();
        $data->markingurl = self::get_markingurl($module);

        return $data;
    }

    /**
     * Add additional data for the grade item where available.
     *
     * @param stdClass $data
     * @return void
     */
    public static function add_additional_data(stdClass &$data): void {
        global $DB;

        $dateformat = get_config('report_feedback_tracker', 'dateformat');

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
                $data->feedbackduedate = date($dateformat, $record->feedbackduedate);

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
     * Get a course module ID from a grade item where available.
     *
     * @param grade_item $gradeitem
     * @return false|mixed
     * @throws dml_exception
     */
    public static function get_cm_from_gradeitem(grade_item $gradeitem) {
        global $DB;

        // SQL query to get the course module ID from a grade item.
        $sql = "
                    SELECT cm.id AS cmid
                        FROM {course_modules} cm
                        JOIN {modules} m ON cm.module = m.id
                        JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
                    WHERE gi.id = :gradeitemid
                ";

        // Execute the query.
        return $DB->get_record_sql($sql, ['gradeitemid' => $gradeitem->id]);
    }

    /**
     * Get a URL to marking.
     *
     * @param cm_info $module
     * @return string
     */
    private static function get_markingurl(cm_info $module): string {
        global $CFG;

        switch ($module->modname) {
            case 'assign':
                return new moodle_url('/mod/assign/view.php', ['id' => $module->id, 'action' => 'grading']);
            case 'quiz':
                return new moodle_url('/mod/quiz/report.php', ['id' => $module->id, 'mode' => 'overview']);
        }

        return $module->get_url();
    }

    /**
     * Save the module data from the module edit form.
     *
     * @param array $params
     * @return void
     */
    public static function save_module_data(array $params): void {
        global $DB, $USER;

        $itemid = $params['itemid'];
        $partid = $params['partid'];
        $contact = $params['contact'];
        $method = $params['method'];
        $hidden = $params['hidden'];
        $generalfeedback = $params['generalfeedback'];
        $feedbackduedate = $params['feedbackduedate'];
        $feedbackreleaseddate = $params['feedbackreleaseddate'];
        $reason = $params['reason'];
        $previousfeedbackduedate = $params['previousfeedbackduedate'];
        $assessmenttype = $params['assessmenttype'];
        $cohortfeedback = $params['cohortfeedback'];
        $customfeedbackduedatecheckbox = $params['customfeedbackduedatecheckbox'];
        $customfeedbackreleaseddatecheckbox = $params['customfeedbackreleaseddatecheckbox'];
        $locked = $params['locked'];

        // Get or create the record.
        if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid, 'partid' => $partid])) {
            $record->responsibility = $contact;
            $record->method = $method;
            $record->hidden = isset($hidden);
            $record->generalfeedback = $generalfeedback;

            // If custom feedback due date is checked.
            if (isset($customfeedbackduedatecheckbox)) {
                // Only save a feedback due date when it has changed.
                if ($feedbackduedate !== $previousfeedbackduedate) {
                    $record->feedbackduedate = strtotime($feedbackduedate);
                }
            } else { // Remove the custom feedback due date.
                $record->feedbackduedate = null;
            }

            // Update the current time as gfdate only if the cohort feedback state has changed.
            if (isset($customfeedbackreleaseddatecheckbox)) {
                if (!$record->gfdate) {
                    $record->gfdate = strtotime($feedbackreleaseddate);
                }
            } else { // If cohort feedback is disabled remove the date.
                if ($record->gfdate) {
                    $record->gfdate = null;
                }
            }

            $DB->update_record('report_feedback_tracker', $record);
        } else {
            $record = new stdClass();
            $record->gradeitem = $itemid;
            $record->partid = $partid;
            $record->responsibility = $contact;
            $record->method = $method;
            $record->hidden = isset($hidden);
            $record->generalfeedback = $generalfeedback;

            // Save a feedback due date only when it has changed.
            if ($feedbackduedate !== $previousfeedbackduedate) {
                $record->feedbackduedate = strtotime($feedbackduedate);
            }

            // Save the current time as gfdate if cohort feedback is set.
            if ($cohortfeedback) {
                $record->gfdate = time();
            }

            $DB->insert_record('report_feedback_tracker', $record);
        }

        // If there is a new manually set feedback due date store the reason for it in a different table.
        if (($feedbackduedate !== $previousfeedbackduedate) && $reason) {
            $record = new stdClass();
            $record->gradeitem = $itemid;
            $record->partid = $partid;
            $record->feedbackduedate = strtotime($feedbackduedate);
            $record->reason = $reason;
            $record->userid = $USER->id;
            $record->changedate = time();
            $DB->insert_record('report_feedback_tracker_duedates', $record);
        }

        // If the assessment type is not locked update it separately in the local_assess_type table.
        if (!$locked && ($gradeitem = $DB->get_record('grade_items', ['id' => $itemid]))) {
            // Update course module records.
            if ($gradeitem->itemtype === 'mod') {
                if ($cm = get_coursemodule_from_instance($gradeitem->itemmodule, $gradeitem->iteminstance)) {
                    assess_type::update_type($gradeitem->courseid, $assessmenttype, $cm->id);
                }
            } else {
                // Update the gradebook grade item and category.
                assess_type::update_type($gradeitem->courseid, $assessmenttype, 0, $itemid);
            }
        }
    }

    /**
     * Return the current reason for a custom feedback due date or false.
     *
     * @param int $gradeitemid
     * @param string $feedbackduedate
     * @return string
     */
    public static function get_reason(int $gradeitemid, string $feedbackduedate): string {
        global $DB;

        $params = ['gradeitem' => $gradeitemid, 'feedbackduedate' => strtotime($feedbackduedate)];
        $record = $DB->get_record('report_feedback_tracker_duedates', $params, '*', IGNORE_MULTIPLE);
        if (isset($record->reason)) {
            return $record->reason;
        }

        return "";
    }

}
