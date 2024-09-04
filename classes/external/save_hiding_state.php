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
 * External API for saving the summative state.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class save_hiding_state extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'The ID of the grade item'),
            'hidingstate' => new external_value(PARAM_BOOL, 'The summative state (0 or 1)'),
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
     * Saving the hiding state for a grade item.
     *
     * @param int $itemid
     * @param bool $hidingstate
     * @return bool|array
     */
    public static function execute(int $itemid, bool $hidingstate): bool {
        try {
            global $DB;

            if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
                $record->hidden = $hidingstate;
                $DB->update_record('report_feedback_tracker', $record);
            } else {
                $record = new stdClass();
                $record->gradeitem = $itemid;
                $record->hidden = $hidingstate;
                $record->feedbackduedate = 0;
                $DB->insert_record('report_feedback_tracker', $record);
            }

            return $hidingstate;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}