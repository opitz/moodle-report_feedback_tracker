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
use local_assess_type\assess_type;
use stdClass;

/**
 * External API for saving the summative state.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class save_assessment_type extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'The ID of the grade item'),
            'partname' => new external_value(PARAM_TEXT, 'The optional part name used by turnitintooltwo only'),
            'assessmenttype' => new external_value(PARAM_INT, 'The ID of the assessment type'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return \external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_INT, 'Success');
    }

    /**
     * Saving the summative state for a grade item.
     *
     * @param int $itemid
     * @param string|null $partname optional partname for turnitintooltwo assessments only.
     * @param int $assessmenttype
     * @return int
     * @throws \Exception
     */
    public static function execute(int $itemid, string|null $partname, int $assessmenttype): int {
        try {
            global $DB;

            // Prepare the type value for the local_assess_type table.
            $type = $assessmenttype;

            // Update summative state in local_assess_type table.
            $gradeitem = $DB->get_record('grade_items', ['id' => $itemid]);
            if (!empty($gradeitem)) {
                // Update course module records.
                if ($gradeitem->itemtype === 'mod') {
                    if ($cm = get_coursemodule_from_instance($gradeitem->itemmodule, $gradeitem->iteminstance)) {
                        assess_type::update_type($gradeitem->courseid, $type, $cm->id);
                    }
                } else {
                    // Update the gradebook grade item and category.
                    assess_type::update_type($gradeitem->courseid, $type, 0, $itemid);
                }
            }

            return $assessmenttype;
        } catch (\Exception $e) {
            throw($e);
        }
    }
}
