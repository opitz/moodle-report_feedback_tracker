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
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;

require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('id', null, PARAM_INT);
$userid = optional_param('userid', null, PARAM_INT);

// If there is no course ID given redirect to the user report.
if (!$courseid) {
    // A user with a teacher role will see the site report if it is enabled in the settigs.
    if (helper::is_teacher() && get_config('report_feedback_tracker', 'sitereport')) {
        redirect(new moodle_url('/report/feedback_tracker/site.php'));
    }
    redirect(new moodle_url('/report/feedback_tracker/student.php'));
}
// If there is a userid or the logged-in user has no rights redirect to the user report.
if ($userid || !helper::is_course_editor($courseid, $USER->id)) {
    redirect(new moodle_url(
        '/report/feedback_tracker/student.php',
        ['id' => $courseid, 'userid' => $userid, 'type' => helper::ASSESS_TYPE_ALL + 1]
    ));
}

$course = isset($courseid) ? get_course($courseid) : $COURSE;
require_login($course);

// If this was called from a form take care of the form data.
if (data_submitted() && confirm_sesskey()) {
    $params['itemid'] = required_param('itemid', PARAM_INT);
    $params['partid'] = optional_param('partid', null, PARAM_INT);
    $params['contact'] = optional_param('contact', null, PARAM_TEXT);
    $params['method'] = optional_param('method', null, PARAM_TEXT);
    $params['hidden'] = optional_param('hidden', null, PARAM_BOOL);
    $params['generalfeedback'] = optional_param('generalfeedback', null, PARAM_TEXT);
    $params['feedbackduedate'] = optional_param('feedbackduedate', null, PARAM_TEXT);
    $params['feedbackreleaseddate'] = optional_param('feedbackreleaseddate', null, PARAM_TEXT);
    $params['reason'] = optional_param('reason', null, PARAM_TEXT);
    $params['previousfeedbackduedate'] = optional_param('previousfeedbackduedate', null, PARAM_TEXT);
    $params['assesstype'] = optional_param('assesstype', null, PARAM_INT);
    $params['cohortfeedback'] = optional_param('cohortfeedback', null, PARAM_INT);
    $params['customfeedbackduedatecheckbox'] = optional_param('customfeedbackduedatecheckbox', null, PARAM_INT);
    $params['customfeedbackreleaseddatecheckbox'] = optional_param('customfeedbackreleaseddatecheckbox', null, PARAM_INT);
    $params['locked'] = optional_param('locked', null, PARAM_INT);

    admin::save_module_data($params);
}

$pageparams = ['id' => $course->id];

$PAGE->set_url('/report/feedback_tracker/index.php', $pageparams);
$PAGE->set_pagelayout('base'); // No drawers.

$context = context_course::instance($course->id);

// Setup array of course assessment types.
helper::$assesstypes = helper::get_assessment_types($course->id);


// Set the header and print it.
$PAGE->set_title($course->shortname . ': ' . get_string('pluginname', 'report_feedback_tracker'));
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

// Print selector drop down.
$pluginname = get_string('pluginname', 'report_feedback_tracker');
report_helper::print_report_selector($pluginname);

// Get the renderer and use it.
$renderer = $PAGE->get_renderer('report_feedback_tracker');
echo $renderer->render_feedback_tracker_course_report($courseid);

echo $OUTPUT->footer();
