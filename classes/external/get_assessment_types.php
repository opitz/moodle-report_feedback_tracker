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

/**
 * External API for getting assessment type options.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class get_assessment_types extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'selection' => new external_value(PARAM_INT, 'The value of the selected assessment type, -1 not set'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return \external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Assessment types');
    }

    /**
     * Saving the feedback due date and the reason for it for a grade item.
     *
     * @param int|null $selection
     * @return string
     */
    public static function execute($selection) {
        $assesstypes = self::get_assess_types($selection);
        if ($selection < 0) { // Only if no selection has been made yet, add a 'not set' option.
            $unselected = ['value' => -1, 'label' => 'Assessment type not set', 'isselected' => true];
            array_unshift($assesstypes, $unselected); // Put unselected option on top.
        }
        return json_encode($assesstypes);
    }

    /**
     * Get the assessment types.
     *
     * @param int|null $selection
     * @return array
     */
    private static function get_assess_types($selection) {
        return [
            (object)['value' => assess_type::ASSESS_TYPE_FORMATIVE,
                'label' => get_string('formativeoption', 'local_assess_type'),
                'isselected' => (assess_type::ASSESS_TYPE_FORMATIVE === $selection)],
            (object)['value' => assess_type::ASSESS_TYPE_SUMMATIVE,
                'label' => get_string('summativeoption', 'local_assess_type'),
                'isselected' => (assess_type::ASSESS_TYPE_SUMMATIVE === $selection)],
            (object)['value' => assess_type::ASSESS_TYPE_DUMMY,
                'label' => get_string('dummyoption', 'local_assess_type'),
                'isselected' => (assess_type::ASSESS_TYPE_DUMMY === $selection)],
        ];
    }

}
