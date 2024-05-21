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
 * Web service for Feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'report_feedback_tracker_save_summative_state' => [
        'classname'     => 'report_feedback_tracker_external',
        'methodname'    => 'save_summative_state',
        'classpath'     => 'report/feedback_tracker/externallib.php',
        'description'   => 'Update the summative state via ajax',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'report/feedback_tracker:grade',
    ],

    'report_feedback_tracker_save_hiding_state' => [
        'classname'     => 'report_feedback_tracker_external',
        'methodname'    => 'save_hiding_state',
        'classpath'     => 'report/feedback_tracker/externallib.php',
        'description'   => 'Update the hiding state via ajax',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'report/feedback_tracker:grade',
    ],

    'report_feedback_tracker_save_feedback_duedate' => [
        'classname'     => 'report_feedback_tracker_external',
        'methodname'    => 'save_feedback_duedate',
        'classpath'     => 'report/feedback_tracker/externallib.php',
        'description'   => 'Update the custom feedback due date via ajax',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'report/feedback_tracker:grade',
    ],

    'report_feedback_tracker_delete_feedback_duedate' => [
        'classname'     => 'report_feedback_tracker_external',
        'methodname'    => 'delete_feedback_duedate',
        'classpath'     => 'report/feedback_tracker/externallib.php',
        'description'   => 'Delete the custom feedback due date via ajax',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'report/feedback_tracker:grade',
    ],

    'report_feedback_tracker_update_general_feedback' => [
        'classname'     => 'report_feedback_tracker_external',
        'methodname'    => 'update_general_feedback',
        'classpath'     => 'report/feedback_tracker/externallib.php',
        'description'   => 'Update the general feedback via ajax',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'report/feedback_tracker:grade',
    ],

];
