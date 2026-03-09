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
use moodle_url;

/**
 * The lesson module helper class.
 *
 * @package    report_feedback_tracker
 * @copyright  2026 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     m.opitz <m.opitz@ucl.ac.uk>
 */
class mod_lesson_helper extends module_helper {
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
        // Return custom data where available.
        return (int) ($this->module->customdata['deadline'] ?? 0);
    }

    /**
     * Provide a URL of the override settings.
     *
     * @return \moodle_url
     */
    public function get_overrides_url(): \moodle_url {
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

        $params = ['instanceid' => (int) $this->module->instance];
        $sql = "SELECT id, userid, timeseen AS submissiondatetime
                        FROM {lesson_attempts}
                        WHERE lessonid = :instanceid";

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

        $params = ['lessonid' => $gradeitem->iteminstance, 'userid' => $userid];
        $overridedate = $DB->get_field('lesson_overrides', 'deadline', $params);

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
        $sql = "SELECT MAX(timeseen)
                        FROM {lesson_attempts}
                        WHERE userid = :userid
                        AND lessonid = :instance";

        return $DB->get_field_sql($sql, $params) ?? 0;
    }
}
