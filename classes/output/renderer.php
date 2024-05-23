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
 * The renderer.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_feedback_tracker\output;

use plugin_renderer_base;


/**
 * Renderer class for feedback tracker report table.
 */
class renderer extends plugin_renderer_base {

    /**
     * Render the table.
     *
     * @param int $userid
     * @param int $courseid optional course id to limit output.
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_user_table($userid, $courseid = 0): string {
        // Get the table data.
        $feedbacktrackerdata = get_feedback_tracker_user_data($userid, $courseid);
        // Render the table data.
        return $this->output->render_from_template('report_feedback_tracker/usertable', $feedbacktrackerdata);
    }

    /**
     * Render the table for an admin etc.
     *
     * @param int $courseid
     * @return string
     * @throws \moodle_exception
     */
    public function render_feedback_tracker_admin_wrapper($courseid): string {
        // Get the table data.
        $feedbacktrackerdata = get_feedback_tracker_admin_data($courseid);
        $feedbacktrackerdata->courseid = $courseid;
        // Render the table data.
        if ($feedbacktrackerdata->editmode) {
            return $this->output->render_from_template('report_feedback_tracker/adminedittable', $feedbacktrackerdata);
        }
        return $this->output->render_from_template('report_feedback_tracker/adminwrapper', $feedbacktrackerdata);
    }

    public function render_feedback_tracker_admin_table($courseid): string {
        // Get the table data.
        $feedbacktrackerdata = get_feedback_tracker_admin_data($courseid);
        // Render the table data.
        return $this->output->render_from_template('report_feedback_tracker/admintable', $feedbacktrackerdata);
    }

}
