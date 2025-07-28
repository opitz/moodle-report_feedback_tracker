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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use grade_item;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;
use stdClass;

defined('MOODLE_INTERNAL') || die;

/** Include essential files */
require_once($CFG->libdir . '/grade/constants.php');
require_once($CFG->libdir . '/grade/grade_item.php');

/**
 * External API for getting module data into JavaScript for editing.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class get_module_data extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'gradeitemid' => new external_value(PARAM_INT, 'The grade item ID'),
            'partid' => new external_value(PARAM_INT, 'The optional part ID for turnitin assessments'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'gradeitemid' => new external_value(PARAM_INT, 'grade ID'),
            'name' => new external_value(PARAM_TEXT, 'module name'),
            'partid' => new external_value(PARAM_INT, 'turnitin part ID'),
            'assesstype' => new external_value(PARAM_INT, 'assesstype'),
            'locked' => new external_value(PARAM_BOOL, 'locked'),
            'hiddenfromreport' => new external_value(PARAM_BOOL, 'hiddenfromreport'),
            'method' => new external_value(PARAM_TEXT, 'method', VALUE_OPTIONAL),
            'contact' => new external_value(PARAM_TEXT, 'contact', VALUE_OPTIONAL),
            'generalfeedback' => new external_value(PARAM_TEXT, 'generalfeedback', VALUE_OPTIONAL),
            'customfeedbackduedate' => new external_value(PARAM_TEXT, 'customfeedbackduedate', VALUE_OPTIONAL),
            'feedbackduedatereason' => new external_value(PARAM_TEXT, 'feedbackduedatereason', VALUE_OPTIONAL),
            'customfeedbackreleaseddate' => new external_value(PARAM_TEXT, 'customfeedbackreleaseddate', VALUE_OPTIONAL),
            'courseid' => new external_value(PARAM_INT, 'course ID'),
        ]);
    }

    /**
     * Returning editing data for a module.
     *
     * @param int $gradeitemid
     * @param int $partid The optional part ID for Turnitin assessments.
     * @return external_single_structure
     */
    public static function execute($gradeitemid, $partid) {
        $gradeitem = new grade_item(['id' => $gradeitemid]);
        $modinfo = get_fast_modinfo($gradeitem->courseid);
        helper::$assesstypes = helper::get_assessment_types($gradeitem->courseid);

        // Set the page context.
        self::validate_context(context_course::instance($gradeitem->courseid));

        // A manual grade item has no module item - so create one.
        if (!$item = admin::get_module_data($modinfo, $gradeitem)) {
            $item = new stdClass();
            $item->name = $gradeitem->itemname;
            $item->gradeitemid = $gradeitem->id;
            $item->cmid = 0;
            $item->partid = 0;
        }

        if ($gradeitem->itemmodule === 'turnitintooltwo') {
            $data = new stdClass();
            // Add separate data for Turnitin parts.
            helper::add_ttt_data($data, $gradeitem, $item);
            // Get the selected part as item.
            $item = $data->items[$partid];
        } else {
            helper::add_additional_data($item);
        }
        $item->courseid = $gradeitem->courseid;

        return $item;
    }
}
