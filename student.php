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
 * Display a list of activites with their current feedback status for a student.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\report_helper;
use report_feedback_tracker\local\helper;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

$courseid = optional_param('id', null, PARAM_INT);
$userid = optional_param('userid', null, PARAM_INT);

// Require login before accessing user profile/event logging.
if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
} else {
    require_login();
}

// Log a visit.
// Get a programme name where available.
$profile = profile_user_record($USER->id, false);
$data = null;

// Log a report view.
$programme = $profile->programmename ?? null;

if (property_exists($profile, 'programmename') && $programme = $profile->programmename) {
    $data = ['other' => ['programme' => $programme]];
}

$event = \report_feedback_tracker\event\report_viewed::create($data);
$event->trigger();

if ($courseid) {
    $context = context_course::instance($courseid);
}
// If optional course and user ID are given and the user has sufficient rights
// the user report will be shown in a course context.
if ($courseid && $userid && has_capability('moodle/grade:edit', $context)) {
    $pageparams = ['id' => $courseid];
    $course = get_course($courseid);
    // Set the course heading.
    $heading = $course->fullname;
} else {
    $context = \context_system::instance(); // Show the page in system context.
    $userid = $USER->id;
    $course = $SITE;
    $pageparams = [];
    // Set the user heading.
    if (isset($USER->firstname)) {
        $heading = get_string('user:heading', 'report_feedback_tracker', $USER->firstname);
    } else {
        $heading = get_string('nouser:heading', 'report_feedback_tracker');
    }

    // If the student is also a teacher and the site report is enabled provide a link to the site report.
    if (helper::is_teacher() && get_config('report_feedback_tracker', 'sitereport')) {
        $siteurl = new moodle_url('/report/feedback_tracker/site.php');
        $link = "<a class='btn btn-sm btn-secondary' href='$siteurl'><i class='icon fa-solid fa-repeat'></i>" .
            get_string('sitereport', 'report_feedback_tracker') . "</a>";
        $PAGE->add_header_action($link);
    }
}

$PAGE->set_context($context);
$PAGE->set_url('/report/feedback_tracker/student.php', $pageparams);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_feedback_tracker'));
$PAGE->set_heading($heading);

echo $OUTPUT->header();

// In course context show the report selector drop down to course admins using a student view.
if ($courseid && ($USER->id !== $userid)) {
    $pluginname = get_string('pluginname', 'report_feedback_tracker');
    report_helper::print_report_selector($pluginname);
}

// Get the renderer and use it.
$renderer = $PAGE->get_renderer('report_feedback_tracker');
echo $renderer->render_feedback_tracker_student_report($userid, $course->id);

echo $OUTPUT->footer();
