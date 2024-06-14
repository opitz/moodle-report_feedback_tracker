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
 * Settings for the Feedback Tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // Default settings.
    $warningdaysdefault = 14;
    $feedbackdeadlinedaysdefault = 30;
    $feedbackextenddaysdefault = 7;
    $dateformatdefault = get_string('dateformat:default', 'report_feedback_tracker');

    $settings->add(new admin_setting_configtext('report_feedback_tracker/warningdays',
        get_string('settings:warningdays', 'report_feedback_tracker'),
        get_string('settings:warningdaysinfo', 'report_feedback_tracker'),
        $warningdaysdefault, PARAM_RAW, 5));

    $settings->add(new admin_setting_configtext('report_feedback_tracker/feedbackdeadlinedays',
        get_string('settings:feedbackdeadlinedays', 'report_feedback_tracker'),
        get_string('settings:feedbackdeadlinedaysinfo', 'report_feedback_tracker'),
        $feedbackdeadlinedaysdefault, PARAM_RAW, 5));

    $settings->add(new admin_setting_configtext('report_feedback_tracker/feedbackextenddays',
        get_string('settings:feedbackextenddays', 'report_feedback_tracker'),
        get_string('settings:feedbackextenddaysinfo', 'report_feedback_tracker'),
        $feedbackextenddaysdefault, PARAM_RAW, 5));

    $settings->add(new admin_setting_configtext('report_feedback_tracker/dateformat',
        get_string('settings:dateformat', 'report_feedback_tracker'),
        get_string('settings:dateformatinfo', 'report_feedback_tracker'),
        $dateformatdefault, PARAM_RAW, 15));

}
