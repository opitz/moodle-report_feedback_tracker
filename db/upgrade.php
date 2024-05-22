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
 * Upgrading the database.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade this feedback tracker instance
 *
 * @param int $oldversion The old version of the feedback tracker report.
 * @return bool
 */
function xmldb_report_feedback_tracker_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024051300) {

        // Define table report_feedback_tracker to be created.
        $table = new xmldb_table('report_feedback_tracker');

        // Adding fields to table report_feedback_tracker.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('gradeitem', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('summative', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('hidden', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('feedbackduedate', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('method', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('responsibility', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('generalfeedback', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $table->add_field('gfurl', XMLDB_TYPE_CHAR, '255', null, null, null, null);

        // Adding keys to table report_feedback_tracker.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Conditionally launch create table for report_feedback_tracker.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2024052000, 'report', 'feedback_tracker');
    }

    if ($oldversion < 2024052000) {
        $field = new xmldb_field('responsibility', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $dbman->add_field($table, $field);
        $field = new xmldb_field('generalfeedback', XMLDB_TYPE_TEXT, 'medium', null, null, null, null);
        $dbman->add_field($table, $field);
        $field = new xmldb_field('gfurl', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $dbman->add_field($table, $field);

        // Savepoint reached.
        upgrade_plugin_savepoint(true, 2024052000, 'report', 'feedback_tracker');
    }

    return true;
}
