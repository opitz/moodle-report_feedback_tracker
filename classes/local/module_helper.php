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
     * Get the number of students that have a submission due date override for a course module.
     *
     * @return int
     */
    abstract public function get_overrides();

    /**
     * Provide a URL of the override settings of a given course module where available.
     *
     * @return string
     */
    abstract public function get_overrides_url(): string;

    /**
     * Get an array of submissions from enrolled students or groups for the given course module.
     *
     * @param bool $countgroups return group submissions if set to true
     * @return array
     */
    abstract public function get_module_submissions(bool $countgroups = false): array;
}
