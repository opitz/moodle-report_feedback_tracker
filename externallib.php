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
 * External feedback tracker API
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_external\external_files;
use core_external\external_format_value;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use core_external\util as external_util;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->dirroot/user/externallib.php");
require_once($CFG->dirroot.'/report/feedback_tracker/lib.php');

//$PAGE->set_context(context_course::instance($COURSE->id));

/**
 * External functions.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_feedback_tracker_external extends \core_external\external_api {

    /**
     * Describes the parameters for save_summative_state webservice.
     *
     * @return external_function_parameters
     * @since  Moodle 3.1
     */
    public static function save_summative_state_parameters() {
        return new external_function_parameters(
            [
                'itemid' => new external_value(PARAM_RAW, 'The ID of the grade item'),
                'summativestate' => new external_value(PARAM_RAW, 'The summative state (0 or 1)'),
            ]
        );
    }

    /**
     * Saving the summative state for a grade item.
     *
     * @param int $itemid The ID of the grade item
     * @param bool $summativestate The summative state (0 or 1)
     * @return bool will return success.
     */
    public static function save_summative_state(int $itemid, bool $summativestate): bool {
        global $DB;

        if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
            $record->summative = $summativestate;
            $DB->update_record('report_feedback_tracker', $record);
        } else {
            $record = new stdClass();
            $record->gradeitem = $itemid;
            $record->summative = $summativestate;
            $record->feedbackduedate = 0;
            $DB->insert_record('report_feedback_tracker', $record);
        }

        return $summativestate;
    }

    /**
     * Describes the return value for save_summative_state
     *
     * @return external_warnings
     */
    public static function save_summative_state_returns() {
    }

    /**
     * Describes the parameters for save_hiding_state webservice.
     *
     * @return external_function_parameters
     * @since  Moodle 3.1
     */
    public static function save_hiding_state_parameters() {
        return new external_function_parameters(
            [
                'itemid' => new external_value(PARAM_RAW, 'The ID of the grade item'),
                'hidingstate' => new external_value(PARAM_RAW, 'The hiding state (0 or 1)'),
            ]
        );
    }

    /**
     * Saving the hiding state for a grade item.
     *
     * @param int $itemid The ID of the grade item
     * @param bool $hidingstate The hiding state (0 or 1)
     * @return bool will return success.
     */
    public static function save_hiding_state(int $itemid, bool $hidingstate): bool {
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
    }

    /**
     * Describes the return value for save_hiding_state
     *
     * @return external_warnings
     */
    public static function save_hiding_state_returns() {
    }

    /**
     * Describes the parameters for save_feedback_duedate webservice.
     *
     * @return external_function_parameters
     * @since  Moodle 3.1
     */
    public static function save_feedback_duedate_parameters() {
        return new external_function_parameters(
            [
                'itemid' => new external_value(PARAM_RAW, 'The ID of the grade item'),
                'duedate' => new external_value(PARAM_RAW, 'The due date in seconds'),
            ]
        );
    }

    /**
     * Saving the custom feedback due date for a grade item.
     *
     * @param int $itemid The ID of the grade item
     * @param int $duedate The due date in seconds
     * @return bool will return success.
     */
    public static function save_feedback_duedate(int $itemid, int $duedate): bool {
        global $DB;

        if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
            $record->feedbackduedate = $duedate;
            $DB->update_record('report_feedback_tracker', $record);
        } else {
            $record = new stdClass();
            $record->gradeitem = $itemid;
            $record->hidden = 0;
            $record->feedbackduedate = $duedate;
            $DB->insert_record('report_feedback_tracker', $record);
        }
        return true;
    }

    /**
     * Describes the return value for save_feedback_duedate
     *
     * @return external_warnings
     */
    public static function save_feedback_duedate_returns() {
    }

    /**
     * Describes the parameters for delete_feedback_duedate webservice.
     *
     * @return external_function_parameters
     * @since  Moodle 3.1
     */
    public static function delete_feedback_duedate_parameters() {
        return new external_function_parameters(
            [
                'itemid' => new external_value(PARAM_RAW, 'The ID of the grade item'),
            ]
        );
    }

    /**
     * Deleting the custom feedback due date for a grade item.
     *
     * @param int $itemid The ID of the grade item
     * @return bool will return success.
     */
    public static function delete_feedback_duedate(int $itemid): bool {
        global $DB;

        if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
            $record->feedbackduedate = 0;
            $DB->update_record('report_feedback_tracker', $record);
            return true;
        }
        return false;
    }

    /**
     * Describes the return value for delete_feedback_duedate
     *
     * @return external_warnings
     */
    public static function delete_feedback_duedate_returns() {
    }

    /**
     * Describes the parameters for update_general_feedback webservice.
     *
     * @return external_function_parameters
     * @since  Moodle 3.1
     */
    public static function update_general_feedback_parameters() {
        return new external_function_parameters(
            [
                'itemid' => new external_value(PARAM_RAW, 'The ID of the grade item'),
                'generalfeedback' => new external_value(PARAM_RAW, 'The general feedback'),
                'gfurl' => new external_value(PARAM_RAW, 'The URL to general feedback'),
            ]
        );
    }

    /**
     * Update or create the general feedback record for a grade item.
     *
     * @param int $itemid The ID of the grade item
     * @param string $generalfeedback The general feedback text
     * @param int $gfurl The general feedback URL
     * @return bool will return success.
     */
    public static function update_general_feedback(int $itemid, $generalfeedback, $gfurl): bool {
        global $DB;

        if ($record = $DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
            $record->generalfeedback = $generalfeedback;
            $record->gfurl = $gfurl;
            $DB->update_record('report_feedback_tracker', $record);
        } else {
            $record = new stdClass();
            $record->gradeitem = $itemid;
            $record->generalfeedback = clean_param($generalfeedback, PARAM_TEXT);
            $record->gfurl = clean_param($gfurl, PARAM_URL);
            $DB->insert_record('report_feedback_tracker', $record);
        }
        return true;
    }

    /**
     * Describes the return value for update_general_feedback
     *
     * @return external_warnings
     */
    public static function update_general_feedback_returns() {
    }

    /**
     * Describes the parameters for render_student_feedback webservice.
     *
     * @return external_function_parameters
     * @since  Moodle 3.1
     */
    public static function render_student_feedback_parameters() {
        return new external_function_parameters(
            [
                'studentid' => new external_value(PARAM_RAW, 'The ID of the student, 0 for admin'),
                'courseid' => new external_value(PARAM_RAW, 'The ID of the course'),
            ]
        );
    }

    /**
     * Render the feedback table for a student or the admin.
     *
     * @param int $studentid The ID of the grade item
     * @param int $courseid The ID of the course if any.
     * @return string the rendered feedback table.
     */
    public static function render_student_feedback(int $studentid, int $courseid): string {
        global $PAGE;

        // Set the page context.
        $PAGE->set_context(context_course::instance($courseid));
        // Get the renderer and use it.
        $renderer = $PAGE->get_renderer('report_feedback_tracker');
        if ($studentid === 0) { // This is a course admin.
            return $renderer->render_feedback_tracker_admin_table($courseid);
        }
        return $renderer->render_feedback_tracker_user_table($studentid, $courseid);

    }

    /**
     * Describes the return value for delete_feedback_duedate
     *
     * @return external_warnings
     */
    public static function render_student_feedback_returns() {
    }

}
