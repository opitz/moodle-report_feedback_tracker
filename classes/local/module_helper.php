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
use cm_info;
use grade_item;

/**
 * The abstract module helper class.
 *
 * @package    report_feedback_tracker
 * @copyright  2026 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     m.opitz <m.opitz@ucl.ac.uk>
 */
abstract class module_helper {
    /** @var cm_info  */
    protected cm_info|\stdClass $module;

    /**
     * Constructor
     *
     * @param cm_info|\stdClass $module
     */
    public function __construct(cm_info|\stdClass $module) {
        $this->module = $module;
    }

    /**
     * Return the URL to the module marking page
     *
     * @return mixed
     */
    abstract public function get_markingurl();

    /**
     * Return the due date of the module
     *
     * @return mixed
     */
    abstract public function get_duedate();

    /**
     * Provide a URL of the override settings of a given course module where available.
     *
     * @return \moodle_url
     */
    abstract public function get_overrides_url(): \moodle_url;

    /**
     * Return the number of submissions from enrolled students or groups for the given course module.
     *
     * @return int
     */
    abstract public function count_module_submissions(): int;

    /**
     * Get an array of submissions from enrolled students or groups for the given course module.
     *
     * @return array
     */
    abstract public function get_module_submissions(): array;

    /**
     * Count the missing grades for a given grade item.
     *
     * @param int $gradeitemid
     * @param bool $markeronly optional - if set return only missing grades for the user as a marker.
     * @return int
     */
    abstract public function count_missing_grades(int $gradeitemid, bool $markeronly = false): int;

    /**
     * Get a due date for a user including optional overrides and extensions.
     *
     * @param grade_item $gradeitem
     * @param int $userid
     * @return false|int
     */
    abstract public function get_user_duedate(grade_item $gradeitem, int $userid): false|int;

    /**
     * Get the submission date for a grade item and student if any.
     *
     * @param int $userid
     * @param int $instance
     * @param ?int $part turnitintooltwo part number
     * @return int
     */
    abstract public function get_submissiondate(int $userid, int $instance, ?int $part = null): int;

    /**
     * Return a URL to the module item where applicable or to the student feedback tracker page otherwise.
     *
     * @return string
     */
    public function get_module_url(): string {
        global $CFG, $COURSE;

        if (!$this->module) {
            return "$CFG->wwwroot/report/feedback_tracker/student.php";
        }
        return "$CFG->wwwroot/mod/{$this->module->modname}/view.php?id={$this->module->id}";
    }

    /**
     * Get the number of students that have a submission due date override for a given course module.
     *
     * @return int
     */
    public function get_overrides(): int {
        global $DB;

        switch ($this->module->modname) {
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
        $overridetable = $this->module->modname . "_overrides";
        $useroverrides = $DB->get_records_sql("
            SELECT *
            FROM {" . $overridetable . "}
            WHERE $idfield = :moduleid AND userid IS NOT NULL", ['moduleid' => $this->module->instance]);

        foreach ($useroverrides as $useroverride) {
            $overrides[$useroverride->userid] = $useroverride->userid;
        }

        // Get group overrides and users in those groups.
        $groupoverrides = $DB->get_records_sql("
            SELECT gm.*
            FROM {" . $overridetable . "} ao
            JOIN {groups_members} gm ON ao.groupid = gm.groupid
            WHERE ao.$idfield = :moduleid AND ao.groupid IS NOT NULL", ['moduleid' => $this->module->instance]);

        foreach ($groupoverrides as $groupoverride) {
            $overrides[$groupoverride->userid] = $groupoverride->userid;
        }

        // Count users.
        return count($overrides);
    }
}
