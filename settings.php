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
 * Settings for the Feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_feedback_tracker\local\helper;

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    // Default settings.
    $warningdaysdefault = 14;
    $feedbackdeadlinedaysdefault = 20;
    $feedbackextenddaysdefault = 7;
    $dateformatdefault = get_string('dateformat:default', 'report_feedback_tracker');
    $defaultdate = get_string('settings:defaultdate', 'report_feedback_tracker');

    // Setting for module header.
    $settings->add(new admin_setting_heading('report_feedback_tracker_layout',
        get_string('settings:interfaceheading', 'report_feedback_tracker'), ''));

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/oldadmin',
        'Use old admin interface', '', false));

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/modheader',
        get_string('settings:modheader', 'report_feedback_tracker'), '', false));

    // Supported modules.
    $settings->add(new admin_setting_heading('report_feedback_tracker_support',
        get_string('settings:supportheading', 'report_feedback_tracker'), ''));

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/supportassign',
        get_string('settings:supportassignment', 'report_feedback_tracker'), '', true));

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/supportlesson',
        get_string('settings:supportlesson', 'report_feedback_tracker'), '', false));

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/supportmanual',
        get_string('settings:supportmanual', 'report_feedback_tracker'), '', true));

    // Check if TurnitinToolTwo is installed.
    if (file_exists($CFG->dirroot.'/mod/turnitintooltwo/version.php')) {
        $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/supportturnitintooltwo',
            get_string('settings:supportturnitintooltwo', 'report_feedback_tracker'), '', true));
    }

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/supportquiz',
        get_string('settings:supportquiz', 'report_feedback_tracker'), '', true));

    $settings->add(new admin_setting_configcheckbox('report_feedback_tracker/supportworkshop',
        get_string('settings:supportworkshop', 'report_feedback_tracker'), '', false));

    // Dates settings.
    $settings->add(new admin_setting_heading('report_feedback_tracker_dates',
        get_string('settings:datesheading', 'report_feedback_tracker'), ''));

    $settings->add(new admin_setting_configtext('report_feedback_tracker/dateformat',
        get_string('settings:dateformat', 'report_feedback_tracker'),
        get_string('settings:dateformatinfo', 'report_feedback_tracker'),
        $dateformatdefault, PARAM_RAW, 15));

    $settings->add(new admin_setting_configtext('report_feedback_tracker/warningdays',
        get_string('settings:warningdays', 'report_feedback_tracker'),
        get_string('settings:warningdaysinfo', 'report_feedback_tracker'),
        $warningdaysdefault, PARAM_RAW, 5));

    $settings->add(new admin_setting_configtext('report_feedback_tracker/feedbackdeadlinedays',
        get_string('settings:feedbackdeadlinedays', 'report_feedback_tracker'),
        get_string('settings:feedbackdeadlinedaysinfo', 'report_feedback_tracker'),
        $feedbackdeadlinedaysdefault, PARAM_RAW, 5));

    // The closure dates for each academic year present in the system beginning with 2024-25.
    $academicyears = helper::get_academic_years();

    $settings->add(new admin_setting_heading('report_feedback_tracker_closure_dates',
        get_string('settings:closuredatesheading', 'report_feedback_tracker'), ''));

    foreach ($academicyears as $year) {
        // We only need closure dates from 2024-25 on so skipping prior dates.
        if ((int) $year < 2024) {
            continue;
        }
        $suffix = '-' . (string) ((int) substr($year, -2) + 1);
        // The year.
        $settings->add(new admin_setting_heading('report_feedback_tracker_closure_dates_' . $year,
            $year . $suffix, ''));

        $settings->add(new admin_setting_configtext('report_feedback_tracker/closure_xmas_start_' . $year,
            get_string('closure:xmas_start', 'report_feedback_tracker'),
            get_string('closure:xmas_start_info', 'report_feedback_tracker'),
            $defaultdate, PARAM_RAW, 15));

        $settings->add(new admin_setting_configtext('report_feedback_tracker/closure_xmas_end_' . $year,
            get_string('closure:xmas_end', 'report_feedback_tracker'),
            get_string('closure:xmas_end_info', 'report_feedback_tracker'),
            $defaultdate, PARAM_RAW, 15));

        $settings->add(new admin_setting_configtext('report_feedback_tracker/closure_easter_start_' . $year,
            get_string('closure:easter_start', 'report_feedback_tracker'),
            get_string('closure:easter_start_info', 'report_feedback_tracker'),
            $defaultdate, PARAM_RAW, 15));

        $settings->add(new admin_setting_configtext('report_feedback_tracker/closure_easter_end_' . $year,
            get_string('closure:easter_end', 'report_feedback_tracker'),
            get_string('closure:easter_end_info', 'report_feedback_tracker'),
            $defaultdate, PARAM_RAW, 15));

    }

}
