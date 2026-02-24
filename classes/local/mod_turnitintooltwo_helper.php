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

/**
 * The Turnitin module helper class.
 *
 * @package    report_feedback_tracker
 * @copyright  2026 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     m.opitz <m.opitz@ucl.ac.uk>
 */
class mod_turnitintooltwo_helper extends module_helper {
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
        return 0;
    }

    /**
     * Get the number of students that have a submission due date override for the course module.
     *
     * @return int
     */
    public function get_overrides() {
        // Turnitintooltwo has no overrides.
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
}
