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
        if (!isset($courseenrolledusers[$module->course])) {
            $enrolledusers = get_enrolled_users(context_course::instance($this->module->course));
            $courseenrolledusers[$this->module->course] = array_map(fn($user) => $user->id, $enrolledusers);
        }

        $enrolleduserids = $courseenrolledusers[$this->module->course];
        $teamsubmission = $DB->get_field('assign', 'teamsubmission', ['id' => $this->module->instance]);

        if ($teamsubmission && $countgroups) {
            // Get group submissions.
            $sql = "SELECT id, groupid, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = $this->module->instance
                        AND userid = 0
                        AND status = 'submitted'
                        AND latest = 1";
        } else {
            // Get user submissions.
            $sql = "SELECT id, userid, timemodified AS submissiondatetime
                        FROM {assign_submission}
                        WHERE assignment = $this->module->instance
                        AND userid > 0
                        AND status = 'submitted'
                        AND latest = 1";
        }

        $records = $DB->get_records_sql($sql);

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
}
