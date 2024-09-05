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

namespace report_feedback_tracker\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use stdClass;

/**
 * External API for deleting the manual feedback due date.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class delete_feedback_duedate extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'The ID of the grade item'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return \external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_BOOL, 'Success');
    }

    /**
     * Removing the feedback due date for a grade item.
     *
     * @param int $itemid
     * @return bool|array
     */
    public static function execute(int $itemid): bool {
        try {
            global $DB;

            if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
                $record->feedbackduedate = 0;
                $DB->update_record('report_feedback_tracker', $record);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            throw($e);
        }
    }
}