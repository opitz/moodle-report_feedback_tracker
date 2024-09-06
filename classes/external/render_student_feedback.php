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
use core_external\external_value;

/**
 * External API for rendering the assessment data for a given student.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Matthias Opitz <m.opitz@ucl.ac.uk>
 */
class render_student_feedback extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'studentid' => new external_value(PARAM_INT, 'The ID of the student, 0 for admin'),
            'courseid' => new external_value(PARAM_INT, 'The ID of the course'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return \external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_RAW, 'The rendered student assessments');
    }

    /**
     * Saving the feedback due date and the reason for it for a grade item.
     *
     * @param int $itemid
     * @param int $duedate
     * @param string $duedatereason
     * @return bool|array
     */
    public static function execute(int $studentid, int $courseid): string {
        try {
            global $PAGE;

            // Set the page context.
            $PAGE->set_context(context_course::instance($courseid));
            // Get the renderer and use it.
            $renderer = $PAGE->get_renderer('report_feedback_tracker');
            if ($studentid === 0) { // This is a course admin.
                return $renderer->render_feedback_tracker_admin_table($courseid);
            }
            return $renderer->render_feedback_tracker_user_table($studentid, $courseid);
        } catch (\Exception $e) {
            throw($e);
        }
    }
}
