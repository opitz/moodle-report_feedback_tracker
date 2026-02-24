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
        $duedate = self::get_duedate($module);
        $data->duedate = $duedate ? userdate($duedate, $dateformat) : false;
        // The raw date is needed for sorting.
        $data->feedbackduedateraw = $duedate ? helper::get_feedbackduedate($gradeitem, $duedate) : 9999999999;
        $data->feedbackduedate = $data->duedate ? userdate($data->feedbackduedateraw, $dateformat) : false;
        $data->markoverdue = false;

        // Student data.
        $overrides = self::get_overrides($module);
        if ($overrides === 1) {
            $data->overrides = get_string('users:extension', 'report_feedback_tracker');
        } else if ($overrides > 1) {
            $data->overrides = get_string('users:extensions', 'report_feedback_tracker', $overrides);
        }
        $data->overridesurl = self::get_overrides_url($module);
        $submitterids = array_column(self::get_module_submissions($module, true), 'userid');
        $data->submissions = count($submitterids);

        // Grades and markings.
        $data->requiredfeedbacks = self::count_missing_grades($module, $submitterids, $gradeitem->id);
        $data->feedbackpercentage = $data->submissions ?
            round(($data->submissions - $data->requiredfeedbacks) / $data->submissions * 100, 1) : 0;

        $data->url = $module->get_url();
        $data->markingurl = module_helper_factory::create($module)->get_markingurl();

        return $data;
    }

    /**
     * Get the due date of a course module.
     *
     * @param cm_info $cm
     * @return int
     */
    public static function get_duedate(cm_info $cm): int {
        global $DB;

        // Ensure customdata is an array.
        $customdata = (array) $cm->customdata;

        switch ($cm->modname) {
            case 'assign':
                $index = 'duedate';
                break;
            case 'lesson':
                $index = 'deadline';
                break;
            case 'quiz':
                $index = 'timeclose';
                break;
            case 'workshop':
                $index = 'submissionend';
                break;
            case 'lti':
                // Check LTI record has enddatetime.
                $enddatetime = $DB->get_field(
                    'report_feedback_tracker_lti',
                    'enddatetime',
                    ['instanceid' => $cm->instance]
                );
                return !empty($enddatetime) ? (int) $enddatetime : 0;
            case 'coursework':
                // Check Coursework record has deadline.
                $deadline = $DB->get_field(
                    'coursework',
                    'deadline',
                    ['id' => $cm->instance]
                );
                return !empty($deadline) ? (int) $deadline : 0;
            default:
                return 0;
        }
        // Return custom data where available.
        return (int) ($customdata[$index] ?? 0);
    }

    /**
     * Get the number of students that have a submission due date override for a given course module.
     *
     * @param cm_info $module
     * @return int
     */
    private static function get_overrides(cm_info $module): int {
        global $DB;

        switch ($module->modname) {
            case 'assign':
                $idfield = 'assignid';
                break;
            case 'lesson':
                $idfield = 'lessonid';
                break;
            case 'quiz':
                $idfield = 'quiz';
                break;
            default:
                return 0; // Return no overrides.
        }

        $overrides = [];
        // Get user overrides.
        $overridetable = $module->modname . "_overrides";
        $useroverrides = $DB->get_records_sql("
            SELECT *
            FROM {" . $overridetable . "}
            WHERE $idfield = :moduleid AND userid IS NOT NULL", ['moduleid' => $module->instance]);

        foreach ($useroverrides as $useroverride) {
            $overrides[$useroverride->userid] = $useroverride->userid;
        }

        // Get group overrides and users in those groups.
        $groupoverrides = $DB->get_records_sql("
            SELECT gm.*
            FROM {" . $overridetable . "} ao
            JOIN {groups_members} gm ON ao.groupid = gm.groupid
            WHERE ao.$idfield = :moduleid AND ao.groupid IS NOT NULL", ['moduleid' => $module->instance]);

        foreach ($groupoverrides as $groupoverride) {
            $overrides[$groupoverride->userid] = $groupoverride->userid;
        }

        // Count users.
        return count($overrides);
    }

    /**
     * Provide a URL of the override settings of a given course module where available.
     *
     * @param cm_info $module
     * @return string
     */
    private static function get_overrides_url(cm_info $module): string {
        $supportedmodules = ['assign', 'lesson', 'quiz'];
        if (in_array($module->modname, $supportedmodules)) {
            return new moodle_url("/mod/" . $module->modname . "/overrides.php", ["cmid" => $module->id]);
        }
        return "#";
    }

    /**
     * Get an array of submissions from enrolled students or groups for the given course module.
     *
     * @param cm_info|stdClass $module
     * @param bool $countgroups return group submissions if set to true
     * @return array
     */
    public static function get_module_submissions(cm_info|stdClass $module, bool $countgroups = false): array {
        global $DB;
        // Array to store enrolled users per course.
        static $courseenrolledusers = [];

        // Check if enrolled users for this course are already cached.
        if (!isset($courseenrolledusers[$module->course])) {
            $enrolledusers = get_enrolled_users(context_course::instance($module->course));
            $courseenrolledusers[$module->course] = array_map(fn($user) => $user->id, $enrolledusers);
        }

        $enrolleduserids = $courseenrolledusers[$module->course];
        $teamsubmission = false;

        switch ($module->modname) {
            case 'assign':
                $teamsubmission = $DB->get_field('assign', 'teamsubmission', ['id' => $module->instance]);
                if ($teamsubmission && $countgroups) {
                    // Get group submissions.
                    $sql = "SELECT id, groupid, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = $module->instance
                        AND userid = 0
                        AND status = 'submitted'
                        AND latest = 1";
                } else {
                    // Get user submissions.
                    $sql = "SELECT id, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = $module->instance
                        AND userid > 0
                        AND status = 'submitted'
                        AND latest = 1";
                }
                break;
            case 'coursework':
                // If option is set show only submissions from students assigned to the current user as assessor.
                if (get_config('report_feedback_tracker', 'showusermarkings')) {
                    return coursework_submission_figures::get_submissions_for_assessor($module->instance);
                }
                // Otherwise return all finalised submssions regardless of assessor.
                $sql = "SELECT id, userid, timesubmitted AS submissiondatetime
                        FROM {coursework_submissions}
                        WHERE courseworkid = $module->instance
                        AND finalisedstatus = 1";
                break;
            case 'lesson':
                $sql = "SELECT id, userid, timeseen AS submissiondatetime
                        FROM {lesson_attempts}
                        WHERE lessonid = $module->instance";
                break;
            case 'quiz':
                $sql = "SELECT id, userid, timefinish AS submissiondatetime
                        FROM {quiz_attempts}
                        WHERE quiz = $module->instance AND preview = 0";
                break;
            case 'turnitintooltwo':
                $sql = "SELECT id, userid, submission_modified AS submissiondatetime
                        FROM {turnitintooltwo_submissions}
                        WHERE turnitintooltwoid = $module->instance";
                break;
            case 'lti':
                $sql = "SELECT id, userid, submittedat AS submissiondatetime
                        FROM {report_feedback_tracker_lti_usr}
                        WHERE instanceid = $module->instance";
                break;
            case 'workshop':
                $sql = "SELECT id, authorid AS userid, timemodified AS submissiondatetime
                        FROM {workshop_submissions}
                        WHERE workshopid = $module->instance";
                break;
            default:
                return [];
        }

        $records = $DB->get_records_sql($sql);

        // If it is an assignment group/team submission amend the group IDs.
        if (($module->modname == 'assign') && $teamsubmission) {
            if ($countgroups) { // Just return the group records.
                return $records;
            }

            foreach ($records as $record) {
                $groups = groups_get_all_groups($module->course, $record->userid);
                // If a user is a member of one group only assign the group ID, otherwise assign the default group.
                $record->groupid = count($groups) === 1 ? reset($groups)->id : 0;
            }
        }

        // Return only submissions from students that are (still) enrolled into the course.
        return array_filter($records, function ($record) use ($enrolleduserids) {
            return in_array($record->userid, $enrolleduserids);
        });
    }

    /**
     * Count the missing grades for a given grade item.
     *
     * @param cm_info $cm
     * @param array $submitterids An array of user/group IDs that have submitted
     * @param int $gradeitemid
     * @param bool $markeronly optional - if set return only missing grades for the user as a marker.
     * @return int
     */
    public static function count_missing_grades(cm_info $cm, array $submitterids, int $gradeitemid, bool $markeronly = false): int {
        global $DB;

        if (empty($submitterids)) {
            // No submissions - no missing grades.
            return 0;
        }

        if ($markeronly || get_config('report_feedback_tracker', 'showusermarkings')) {
            if ($cm->modname === 'assign') {
                return self::count_assign_marker_submissions($cm->instance);
            } else if ($cm->modname === 'coursework') {
                // Coursework has its own method to only return missing grades for a marker.
                return coursework_submission_figures::calculate_needsgrading_for_assessor($cm->instance);
            }
        }

        // Assignments provide a method to count user - not team! - submissions that need grading.
        if (
            $cm->modname === 'assign' &&
            $DB->get_field('assign', 'teamsubmission', ['id' => $cm->instance]) == 0
        ) {
            $context = context_module::instance($cm->id);
            $assignment = new assign($context, $cm, $cm->course);
            return $assignment->count_submissions_need_grading();
        }

        $sql = "SELECT DISTINCT userid
                  FROM {grade_grades}
                 WHERE itemid = :gradeitemid AND finalgrade > :finalgrade";
        $params = ['gradeitemid' => $gradeitemid, 'finalgrade' => -1];

        $gradedids = $DB->get_fieldset_sql($sql, $params);

        if ($cm->modname === 'assign') {
            // Must be a team submission if we've got this far.

            // Determine the number of groups that have graded submitters.
            $markedgroups = 0;
            $defaultgroup = 0;

            // Get all groups assigned to the module's grouping.
            $groups = groups_get_all_groups($cm->course, 0, $cm->groupingid);

            foreach ($groups as $group) {
                $members = groups_get_members($group->id, 'u.id');
                foreach ($members as $member) {
                    if (in_array($member->id, $gradedids)) {
                        // If the user is only a member of a single group count that group.
                        if (groups_get_user_groups($cm->course, $member->id) === 1) {
                            $markedgroups++;
                        } else { // The user is placed into the default group, so count it once.
                            $defaultgroup = 1;
                        }
                        break;
                    }
                }
            }
            return count($submitterids) - $markedgroups - $defaultgroup;
        } else {
            // Count and return all student IDs in submission that are not (yet) to be found in gradings.
            return count(array_diff($submitterids, $gradedids));
        }
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
