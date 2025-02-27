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
 * Display a list of activites with their current feedback status for a user.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\report_helper;
use report_feedback_tracker\local\helper;

require_once(__DIR__ . '/../../config.php');

$course = ($courseid = optional_param('id', null, PARAM_INT)) ? get_course($courseid) : $COURSE;
$context = context_course::instance($course->id);

if ((!$userid = optional_param('userid', null, PARAM_INT)) ||
        (!has_capability('moodle/grade:edit', $context))) {
    $userid = $USER->id;
}

$pageparams = ['id' => $course->id];
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/report/feedback_tracker/user.php', $pageparams);
$PAGE->set_pagelayout('report');

require_login($course);

// Set the header and print it.
$PAGE->set_title(get_string('pluginname', 'report_feedback_tracker'));
if (isset($USER->firstname)) {
    $PAGE->set_heading(get_string('user:heading', 'report_feedback_tracker', $USER->firstname));
} else {
    $PAGE->set_heading(get_string('nouser:heading', 'report_feedback_tracker'));
}

// If the user is a teacher and the site report is enabled provide a link to the site report.
if (helper::is_teacher() && get_config('report_feedback_tracker', 'sitereport')) {
    $siteurl = new moodle_url('/report/feedback_tracker/site.php');
    $link = "<a class='btn btn-sm btn-secondary' href='$siteurl'><i class='icon fa-solid fa-repeat'></i>" .
        get_string('sitereport', 'report_feedback_tracker') . "</a>";
    $PAGE->add_header_action($link);
}

echo $OUTPUT->header();

// Get the renderer and use it.
$renderer = $PAGE->get_renderer('report_feedback_tracker');
echo $renderer->render_feedback_tracker_user_report($userid, $course->id);

echo $OUTPUT->footer();
