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

    'report_feedback_tracker_update_module' => [
        'classname'         => 'report_feedback_tracker\external\update_module',
        'description'       => 'Update additional information for a module via ajax',
        'type'              => 'write',
        'readonlysession'   => true,
        'ajax'              => true,
        'capabilities'      => 'report/feedback_tracker:grade',
    ],

    'report_feedback_tracker_get_assessment_types' => [
        'classname'         => 'report_feedback_tracker\external\get_assessment_types',
        'description'       => 'Get the assessment type options',
        'type'              => 'read',
        'readonlysession'   => true,
        'ajax'              => true,
        'capabilities'      => 'report/feedback_tracker:grade',
    ],

];
