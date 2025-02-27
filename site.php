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
 * Display a list of activites with their current feedback status for all assessments.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\report_helper;

require_once(__DIR__ . '/../../config.php');

global $OUTPUT, $PAGE, $USER;

// Set the page context.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/report/feedback_tracker/site.php');
$PAGE->set_pagelayout('report');

// If the site report is not enabled redirect to the user report.
if (!get_config('report_feedback_tracker', 'sitereport')) {
    redirect(new moodle_url('/report/feedback_tracker/user.php'));
}

require_login();

// Set the header and print it.
$PAGE->set_title(get_string('pluginname', 'report_feedback_tracker'));
$PAGE->set_heading(get_string('site:heading', 'report_feedback_tracker'));

$studenturl = new moodle_url('/report/feedback_tracker/user.php');
$link = "<a class='btn btn-sm btn-secondary' href='$studenturl'><i class='icon fa-solid fa-repeat'></i>" .
    get_string('userreport', 'report_feedback_tracker') . "</a>";
$PAGE->add_header_action($link);

echo $OUTPUT->header();

// Get the renderer and use it.
$renderer = $PAGE->get_renderer('report_feedback_tracker');
echo $renderer->render_feedback_tracker_site_report();

echo $OUTPUT->footer();
