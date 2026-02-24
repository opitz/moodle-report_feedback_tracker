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
}
