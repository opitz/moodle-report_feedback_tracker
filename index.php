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
 * Display a list of grading items with their current feedback status.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\report_helper;

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

// If there is no course ID given redirect to the user report.
if (!$courseid = optional_param('id', null, PARAM_INT)) {
    redirect(new moodle_url("$CFG->wwwroot/report/feedback_tracker/user.php"));
}

$course = isset($courseid) ? get_course($courseid) : $COURSE;
require_login($course);

$pageparams = ['id' => $course->id];

$PAGE->set_url('/report/feedback_tracker/index.php', $pageparams);
$PAGE->set_pagelayout('report');

$context = context_course::instance($course->id);

// Check if the user is able to see the report and redirect to home if not.
if (!is_course_editor($courseid, $USER->id)) {
    redirect(new moodle_url("/?redirect=0"));
}

// Include the AMD module for manipulating general feedback output.
$PAGE->requires->js_call_amd('report_feedback_tracker/generalfeedback', 'init');

// Set the header and print it.
$PAGE->set_title($course->shortname .':' . get_string('pluginname', 'report_feedback_tracker'));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

// Print selector drop down.
$pluginname = get_string('pluginname', 'report_feedback_tracker');
report_helper::print_report_selector($pluginname);

// Get the renderer and use it.
$renderer = $PAGE->get_renderer('report_feedback_tracker');
echo $renderer->render_feedback_tracker_admin_wrapper($courseid);

echo $OUTPUT->footer();



