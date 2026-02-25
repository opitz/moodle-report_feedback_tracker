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
use grade_item;
use mod_coursework\services\submission_figures as coursework_submission_figures;
use moodle_url;

/**
 * The assignment module helper class.
 *
 * @package    report_feedback_tracker
 * @copyright  2026 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     m.opitz <m.opitz@ucl.ac.uk>
 */
class mod_assign_helper extends module_helper {
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
     * Return the URL to the assignment marking page
     *
     * @return mixed
     */
    public function get_markingurl() {
        return new moodle_url('/mod/assign/view.php', ['id' => $this->module->id, 'action' => 'grading']);
    }

    /**
     * Get the due date of the module
     *
     * @return int
     */
    public function get_duedate() {
        // Ensure customdata is an array.
        $customdata = (array) $this->module->customdata;

        // Return custom data where available.
        return (int) ($customdata['duedate'] ?? 0);
    }

    /**
     * Get the number of students that have a submission due date override for the course module.
     *
     * @return int
     */
    public function get_overrides() {
        return helper::get_overrides($this->module);
    }

    /**
     * Provide a URL of the override settings.
     *
     * @return string
     */
    public function get_overrides_url(): string {
        return new moodle_url("/mod/" . $this->module->modname . "/overrides.php", ["cmid" => $this->module->id]);
    }

    /**
     * Get an array of submissions from enrolled students or groups for the given course module.
     *
     * @param bool $countgroups return group submissions if set to true
     * @return array
     */
    public function get_module_submissions(bool $countgroups = false): array {
        global $DB;

        // Array to store enrolled users per course.
        static $courseenrolledusers = [];

        // Check if enrolled users for this course are already cached.
        if (!isset($courseenrolledusers[$this->module->course])) {
            $enrolledusers = get_enrolled_users(context_course::instance($this->module->course));
            $courseenrolledusers[$this->module->course] = array_map(fn($user) => $user->id, $enrolledusers);
        }

        $enrolleduserids = $courseenrolledusers[$this->module->course];
        $teamsubmission = $DB->get_field('assign', 'teamsubmission', ['id' => $this->module->instance]);

        $params = ['moduleinstance' => $this->module->instance];
        if ($teamsubmission && $countgroups) {
            // Get group submissions.
            $sql = "SELECT id, groupid, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = :moduleinstance
                        AND userid = 0
                        AND status = 'submitted'
                        AND latest = 1";
        } else {
            // Get user submissions.
            $sql = "SELECT id, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = :moduleinstance
                        AND userid > 0
                        AND status = 'submitted'
                        AND latest = 1";
        }

        $records = $DB->get_records_sql($sql, $params);

        // If it is an assignment group/team submission amend the group IDs.
        if ($teamsubmission) {
            if ($countgroups) { // Just return the group records.
                return $records;
            }

            foreach ($records as $record) {
                $groups = groups_get_all_groups($this->module->course, $record->userid);
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
     * @param int $gradeitemid
     * @param bool $markeronly optional - if set return only missing grades for the user as a marker.
     * @return int
     */
    public function count_missing_grades(int $gradeitemid, bool $markeronly = false): int {
        global $DB;

        $submitterids = array_column(module_helper_factory::create($this->module)->get_module_submissions(true), 'userid');

        // No submissions - no missing grades.
        if (empty($submitterids)) {
            return 0;
        }

        if ($markeronly || get_config('report_feedback_tracker', 'showusermarkings')) {
            return self::count_assign_marker_submissions($this->module->instance);
        }

        // Assignments provide a method to count user - not team! - submissions that need grading.
        if ($DB->get_field('assign', 'teamsubmission', ['id' => $this->module->instance]) == 0) {
            $context = context_module::instance($this->module->id);
            $assignment = new assign($context, $this->module, $this->module->course);
            return $assignment->count_submissions_need_grading();
        }

        // Must be a team submission if we've got this far.
        $sql = "SELECT DISTINCT userid
                  FROM {grade_grades}
                 WHERE itemid = :gradeitemid AND finalgrade > :finalgrade";
        $params = ['gradeitemid' => $gradeitemid, 'finalgrade' => -1];

        $gradedids = $DB->get_fieldset_sql($sql, $params);

        // Determine the number of groups that have graded submitters.
        $markedgroups = 0;
        $defaultgroup = 0;

        // Get all groups assigned to the module's grouping.
        $groups = groups_get_all_groups($this->module->course, 0, $this->module->groupingid);

        foreach ($groups as $group) {
            $members = groups_get_members($group->id, 'u.id');
            foreach ($members as $member) {
                if (in_array($member->id, $gradedids)) {
                    // If the user is only a member of a single group count that group.
                    if (groups_get_user_groups($this->module->course, $member->id) === 1) {
                        $markedgroups++;
                    } else { // The user is placed into the default group, so count it once.
                        $defaultgroup = 1;
                    }
                    break;
                }
            }
        }
        return count($submitterids) - $markedgroups - $defaultgroup;
    }

    /**
     * Get a due date for a user including optional overrides and extensions.
     *
     * @param grade_item $gradeitem
     * @param int $userid
     * @return false|int
     */
    public function get_user_duedate(grade_item $gradeitem, int $userid): false|int {
        global $DB;

        // Get individual override where available.
        $params = ['assignid' => $gradeitem->iteminstance, 'userid' => $userid];
        $overridedate = $DB->get_field('assign_overrides', 'duedate', $params);

        // If there is no individual override check for a group override date.
        if (!$overridedate) {
            $usergroups = groups_get_user_groups($gradeitem->courseid, $userid);
            foreach ($usergroups[0] as $usergroupid) {
                $params = ['assignid' => $gradeitem->iteminstance, 'groupid' => $usergroupid];
                $overrideduedate = $DB->get_field('assign_overrides', 'duedate', $params);

                if ($overrideduedate > $overridedate) {
                    $overridedate = $overrideduedate;
                }
            }
        }

        // Get individual extension where available.
        $params = ['assignment' => $gradeitem->iteminstance, 'userid' => $userid];
        $extensiondate = $DB->get_field('assign_user_flags', 'extensionduedate', $params);

        // Use the date that gives the most time to the student.
        if ($extensiondate > $overridedate) {
            $overridedate = $extensiondate;
        }

        return  $overridedate ?: $this->get_duedate();
    }

    /**
     * Get the submission date for a grade item and student if any.
     *
     * @param int $userid
     * @param int $instance
     * @param ?int $part turnitintooltwo part number
     * @return int
     */
    public function get_submissiondate(int $userid, int $instance, ?int $part = null): int {
        global $DB;

        $params = ['userid' => $userid, 'instance' => $instance];
        $sql = "SELECT MAX(timemodified)
                        FROM {assign_submission}
                        WHERE userid = :userid
                        AND assignment = :instance
                        AND status = 'submitted'";

        return $DB->get_field_sql($sql, $params) ?? 0;
    }
}
