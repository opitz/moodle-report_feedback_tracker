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
use assign;
use context_course;
use context_module;
use course_modinfo;
use grade_item;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;
use cm_info;
use mod_coursework\services\submission_figures as coursework_submission_figures;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * This file contains the admin functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin {
    /**
     * Get a course module to the grade item where available and return a record for it.
     *
     * @param course_modinfo $modinfo
     * @param grade_item $gradeitem
     * @return false|stdClass
     */
    public static function get_module_data(
        course_modinfo $modinfo,
        grade_item $gradeitem
    ): false|stdClass {
        $module = helper::get_module_from_gradeitem($gradeitem, $modinfo);

        if (!$module) {
            return false;
        }

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');

        // Build the module data.
        $data = new stdClass();
        $data->gradeitemid = $gradeitem->id;
        $data->name = $gradeitem->itemname; // The grade item name has more details.
        $data->moduletypeiconurl = $module->get_icon_url()->out(false);

        $data->cmid = $module->id;
        $data->partid = null;

        // Hiding attributes.
        $data->hiddenfromstudents = !$module->visible;
        $data->hiddendisabled = true;

        $data->modname = $module->modname;

        // Dates.
        $duedate = module_helper_factory::create($module)->get_duedate();

        $data->duedate = $duedate ? userdate($duedate, $dateformat) : false;
        // The raw date is needed for sorting.
        $data->feedbackduedateraw = $duedate ? helper::get_feedbackduedate($gradeitem, $duedate) : 9999999999;
        $data->feedbackduedate = $data->duedate ? userdate($data->feedbackduedateraw, $dateformat) : false;
        $data->markoverdue = false;

        // Student data.
        $overrides = module_helper_factory::create($module)->get_overrides();
        if ($overrides === 1) {
            $data->overrides = get_string('users:extension', 'report_feedback_tracker');
        } else if ($overrides > 1) {
            $data->overrides = get_string('users:extensions', 'report_feedback_tracker', $overrides);
        }
        $data->overridesurl = module_helper_factory::create($module)->get_overrides_url();
        $submitterids = array_column(module_helper_factory::create($module)->get_module_submissions(true), 'userid');
        $data->submissions = count($submitterids);

        // Grades and markings.
        $data->requiredfeedbacks = module_helper_factory::create($module)->count_missing_grades($gradeitem->id);
        $data->feedbackpercentage = $data->submissions ?
            round(($data->submissions - $data->requiredfeedbacks) / $data->submissions * 100, 1) : 0;

        $data->url = $module->get_url();
        $data->markingurl = module_helper_factory::create($module)->get_markingurl();

        return $data;
    }

    /**
     * Count the assignment submissions for the current marker user.
     *
     * @param int $assignid The assignment id.
     * @return int Number of markers submissions.
     */
    private static function count_assign_marker_submissions(int $assignid): int {
        global $DB, $USER;

        // First, get all submissions the user is allowed to see.
        $params = ['assignid' => $assignid];
        $sql = "SELECT id, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = :assignid
                        AND userid > 0
                        AND status = 'submitted'
                        AND latest = 1";
        $submissions = $DB->get_records_sql($sql, $params);

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        $filtered = [];

        foreach ($submissions as $submission) {
            // Ignore submissions that have been graded.
            if (assign_get_user_grades($assign, $submission->userid)) {
                continue;
            }
            // Look up the user flag for this student.
            $allocatedmarker = $DB->get_field('assign_user_flags', 'allocatedmarker', [
                'assignment' => $assignid,
                'userid' => $submission->userid,
            ]);

            // Include submission if:
            // - No marker assigned, or
            // - Current user is the assigned marker.
            if ($allocatedmarker === false || $allocatedmarker == $USER->id) {
                $filtered[] = $submission;
            }
        }

        return count($filtered);
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
        $partid = $params['partid'] ?: null;
        $contact = $params['contact'];
        $method = $params['method'];
        $hidden = $params['hidden'];
        $generalfeedback = $params['generalfeedback'];
        $feedbackduedate = $params['feedbackduedate'];
        $feedbackreleaseddate = $params['feedbackreleaseddate'];
        $reason = $params['reason'];
        $prevreason = helper::get_reason($itemid, $partid, $feedbackduedate);
        $previousfeedbackduedate = $params['previousfeedbackduedate'];
        $assesstype = $params['assesstype'];
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

            if (isset($customfeedbackduedatecheckbox)) {
                $record->feedbackduedate = strtotime($feedbackduedate);
            } else { // Remove the custom feedback due date.
                $record->feedbackduedate = null;
            }

            if (isset($customfeedbackreleaseddatecheckbox)) {
                $record->gfdate = strtotime($feedbackreleaseddate);
            } else { // Remove the custom feedback released date.
                $record->gfdate = null;
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
            $record->feedbackduedate = strtotime($feedbackduedate);

            if (isset($customfeedbackreleaseddatecheckbox)) {
                $record->gfdate = strtotime($feedbackreleaseddate);
            } else { // Remove the custom feedback released date.
                $record->gfdate = null;
            }

            $DB->insert_record('report_feedback_tracker', $record);
        }

        // If the reason or the date has changed log it.
        if (
            $reason &&
                (($feedbackduedate !== $previousfeedbackduedate) ||
                ($reason !== $prevreason))
        ) {
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
                    assess_type::update_type($gradeitem->courseid, $assesstype, $cm->id);
                }
            } else {
                // Update the gradebook grade item and category.
                assess_type::update_type($gradeitem->courseid, $assesstype, 0, $itemid);
            }
        }
    }
}
