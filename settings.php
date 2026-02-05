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

// Add an entry to the site administration reports.
if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_externalpage(
        'feedback_tracker_visits',
        get_string('visitslog:title', 'report_feedback_tracker'),
        new moodle_url('/report/feedback_tracker/visits_log.php'),
        'report/feedback_tracker:view'
    ));
}

if ($ADMIN->fulltree) {
    // Default settings.
    $warningdaysdefault = 14;
    $feedbackdeadlinedaysdefault = 20;
    $defaultdate = get_string('settings:defaultdate', 'report_feedback_tracker');

    $pluginmanager = \core_plugin_manager::instance();

    // Use site report option.
    // Only available if block_portico_enrollments is installed.
    if (file_exists($CFG->dirroot . "/blocks/portico_enrolments/version.php")) {
        $settings->add(new admin_setting_configcheckbox(
            'report_feedback_tracker/sitereport',
            get_string('settings:sitereport', 'report_feedback_tracker'),
            '',
            false
        ));
    } else {
        set_config('sitereport', false, 'report_feedback_tracker');
    }

    // Option to show only relevant submissions and markings to markers where applicable (e.g. Courseworks).
    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/showusermarkings',
        get_string('settings:showusermarkings', 'report_feedback_tracker'),
        get_string('settings:showusermarkingsdescription', 'report_feedback_tracker'),
        false
    ));

    // Data export options.
    $settings->add(new admin_setting_heading(
        'report_feedback_tracker_export',
        get_string('settings:exportheading', 'report_feedback_tracker'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'report_feedback_tracker/export_academicyear',
        get_string('settings:export_academicyear', 'report_feedback_tracker'),
        get_string('settings:export_academicyearinfo', 'report_feedback_tracker'),
        '',
        PARAM_RAW,
        5
    ));

    $settings->add(new admin_setting_configtext(
        'report_feedback_tracker/export_path',
        get_string('settings:export_path', 'report_feedback_tracker'),
        get_string('settings:export_pathinfo', 'report_feedback_tracker'),
        '',
        PARAM_RAW,
        50
    ));

    $settings->add(new admin_setting_configtext(
        'report_feedback_tracker/export_limit',
        get_string('settings:export_limit', 'report_feedback_tracker'),
        get_string('settings:export_limitinfo', 'report_feedback_tracker'),
        '',
        PARAM_RAW,
        5
    ));

    // Supported modules.
    $settings->add(new admin_setting_heading(
        'report_feedback_tracker_support',
        get_string('settings:supportheading', 'report_feedback_tracker'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/supportassign',
        get_string('settings:supportassignment', 'report_feedback_tracker'),
        '',
        true
    ));

    // Check if Coursework is installed.
    if ($pluginmanager->get_plugin_info('mod_coursework')) {
        $settings->add(new admin_setting_configcheckbox(
            'report_feedback_tracker/supportcoursework',
            get_string('settings:supportcoursework', 'report_feedback_tracker'),
            '',
            false
        ));
    }

    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/supportlesson',
        get_string('settings:supportlesson', 'report_feedback_tracker'),
        '',
        false
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/supportlti',
        get_string('settings:supportlti', 'report_feedback_tracker'),
        '',
        false
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/supportmanual',
        get_string('settings:supportmanual', 'report_feedback_tracker'),
        '',
        true
    ));

    // Check if TurnitinToolTwo is installed.
    if ($pluginmanager->get_plugin_info('mod_turnitintooltwo')) {
        $settings->add(new admin_setting_configcheckbox(
            'report_feedback_tracker/supportturnitintooltwo',
            get_string('settings:supportturnitintooltwo', 'report_feedback_tracker'),
            '',
            true
        ));
    }

    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/supportquiz',
        get_string('settings:supportquiz', 'report_feedback_tracker'),
        '',
        true
    ));

    $settings->add(new admin_setting_configcheckbox(
        'report_feedback_tracker/supportworkshop',
        get_string('settings:supportworkshop', 'report_feedback_tracker'),
        '',
        false
    ));

    // Dates settings.
    $settings->add(new admin_setting_heading(
        'report_feedback_tracker_dates',
        get_string('settings:datesheading', 'report_feedback_tracker'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'report_feedback_tracker/warningdays',
        get_string('settings:warningdays', 'report_feedback_tracker'),
        get_string('settings:warningdaysinfo', 'report_feedback_tracker'),
        $warningdaysdefault,
        PARAM_RAW,
        5
    ));

    $settings->add(new admin_setting_configtext(
        'report_feedback_tracker/feedbackdeadlinedays',
        get_string('settings:feedbackdeadlinedays', 'report_feedback_tracker'),
        get_string('settings:feedbackdeadlinedaysinfo', 'report_feedback_tracker'),
        $feedbackdeadlinedaysdefault,
        PARAM_RAW,
        5
    ));

    // The closure dates for each academic year present in the system beginning with 2024-25.
    $academicyears = helper::get_academic_years();

    $settings->add(new admin_setting_heading(
        'report_feedback_tracker_closure_dates',
        get_string('settings:closuredatesheading', 'report_feedback_tracker'),
        ''
    ));

    foreach ($academicyears as $year) {
        // We only need closure dates from 2024-25 on so skipping prior dates.
        if ((int) $year < 2024) {
            continue;
        }
        $suffix = '-' . (string) ((int) substr($year, -2) + 1);
        // The year.
        $settings->add(new admin_setting_heading(
            'report_feedback_tracker_closure_dates_' . $year,
            $year . $suffix,
            ''
        ));

        $settings->add(new admin_setting_configtext(
            'report_feedback_tracker/closure_xmas_start_' . $year,
            get_string('closure:xmas_start', 'report_feedback_tracker'),
            get_string('closure:xmas_start_info', 'report_feedback_tracker'),
            $defaultdate,
            PARAM_RAW,
            15
        ));

        $settings->add(new admin_setting_configtext(
            'report_feedback_tracker/closure_xmas_end_' . $year,
            get_string('closure:xmas_end', 'report_feedback_tracker'),
            get_string('closure:xmas_end_info', 'report_feedback_tracker'),
            $defaultdate,
            PARAM_RAW,
            15
        ));

        $settings->add(new admin_setting_configtext(
            'report_feedback_tracker/closure_easter_start_' . $year,
            get_string('closure:easter_start', 'report_feedback_tracker'),
            get_string('closure:easter_start_info', 'report_feedback_tracker'),
            $defaultdate,
            PARAM_RAW,
            15
        ));

        $settings->add(new admin_setting_configtext(
            'report_feedback_tracker/closure_easter_end_' . $year,
            get_string('closure:easter_end', 'report_feedback_tracker'),
            get_string('closure:easter_end_info', 'report_feedback_tracker'),
            $defaultdate,
            PARAM_RAW,
            15
        ));
    }
}
