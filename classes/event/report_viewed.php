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

/**
 * Log a student visit event.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */

namespace report_feedback_tracker\event;

/**
 * Report view event.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class report_viewed extends \core\event\base {
    /**
     * Initialisation
     *
     * @return void
     */
    protected function init() {
        $this->context = \context_system::instance();
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Get the event name
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:report_viewed', 'report_feedback_tracker');
    }

    /**
     * Get the description
     *
     * @return string|null
     */
    public function get_description() {
        return "The user with id '$this->userid' viewed the feedback tracker report.";
    }
}
