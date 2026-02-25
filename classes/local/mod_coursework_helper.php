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

use context_course;
use grade_item;
use mod_coursework\services\submission_figures as coursework_submission_figures;

/**
 * The coursework module helper class.
 *
 * @package    report_feedback_tracker
 * @copyright  2026 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     m.opitz <m.opitz@ucl.ac.uk>
 */
class mod_coursework_helper extends module_helper {
    /**
     * Return the URL to the module page
     *
     * @return mixed
     */
    public function get_markingurl() {
        return $this->module->get_url();
    }

    /**
     * Get the due date of the module
     *
     * @return int
     */
    public function get_duedate() {
        global $DB;

        // Check Coursework record has deadline.
        $deadline = $DB->get_field(
            'coursework',
            'deadline',
            ['id' => $this->module->instance]
        );
        return !empty($deadline) ? (int) $deadline : 0;
    }

    /**
     * Get the number of students that have a submission due date override for the course module.
     *
     * @return int
     */
    public function get_overrides() {
        // Coursework has no overrides.
        return 0;
    }

    /**
     * Provide a URL of the override settings.
     *
     * @return string
     */
    public function get_overrides_url(): string {
        // This module has no override settings.
        return "#";
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

        // If option is set show only submissions from students assigned to the current user as assessor.
        if (get_config('report_feedback_tracker', 'showusermarkings')) {
            return coursework_submission_figures::get_submissions_for_assessor($this->module->instance);
        }

        // Otherwise return all finalised submssions regardless of assessor.
        $params = ['instanceid' => (int) $this->module->instance];
        $sql = "SELECT id, userid, timesubmitted AS submissiondatetime
                        FROM {coursework_submissions}
                        WHERE courseworkid = :instanceid
                        AND finalisedstatus = 1";

        $records = $DB->get_records_sql($sql, $params);

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
            // Coursework has its own method to only return missing grades for a marker.
            return coursework_submission_figures::calculate_needsgrading_for_assessor($this->module->instance);
        }

        $sql = "SELECT DISTINCT userid
                  FROM {grade_grades}
                 WHERE itemid = :gradeitemid AND finalgrade > :finalgrade";
        $params = ['gradeitemid' => $gradeitemid, 'finalgrade' => -1];

        $gradedids = $DB->get_fieldset_sql($sql, $params);

        // Count and return all student IDs in submission that are not (yet) to be found in gradings.
        return count(array_diff($submitterids, $gradedids));
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
        $params = [
            'courseworkid' => $gradeitem->iteminstance,
            'allocatableid' => $userid,
            'allocatabletype' => 'user',
        ];
        $overridedate = $DB->get_field('coursework_person_deadlines', 'personaldeadline', $params);

        // If there is no individual override check for a group override date.
        if (!$overridedate) {
            $usergroups = groups_get_user_groups($gradeitem->courseid, $userid);
            foreach ($usergroups[0] as $usergroupid) {
                $params = [
                    'courseworkid' => $gradeitem->iteminstance,
                    'allocatableid' => $usergroupid,
                    'allocatabletype' => 'group',
                ];
                $overrideduedate = $DB->get_field('coursework_extensions', 'extended_deadline', $params);

                if ($overrideduedate > $overridedate) {
                    $overridedate = $overrideduedate;
                }
            }
        }

        return  $overridedate ?: $this->get_duedate();
    }
}
