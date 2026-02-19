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

namespace report_feedback_tracker\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context_system;

/**
 * Privacy provider for report_feedback_tracker.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards UCL <m.opitz@ucl.ac.uk>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider
{
    /**
     * Return the language string explaining why this plugin stores data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }

    /**
     * Describe the personal data stored.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'report_feedback_tracker_duedates',
            [
                'userid' => 'privacy:metadata:userid',
            ],
            'privacy:metadata:report_feedback_tracker_duedates'
        );

        $collection->add_database_table(
            'report_feedback_tracker_lti_usr',
            [
                'userid' => 'privacy:metadata:userid',
            ],
            'privacy:metadata:report_feedback_tracker_lti_usr'
        );

        return $collection;
    }

    /**
     * Get contexts containing user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // All data is stored at system level.
        $contextlist->add_system_context();

        return $contextlist;
    }

    /**
     * Export all user data.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        if (!in_array(context_system::instance()->id, $contextlist->get_contextids())) {
            return;
        }

        $data = [];

        $data['duedates'] = $DB->get_records(
            'report_feedback_tracker_duedates',
            ['userid' => $userid]
        );

        $data['lti_usr'] = $DB->get_records(
            'report_feedback_tracker_lti_usr',
            ['userid' => $userid]
        );

        writer::with_context(context_system::instance())
            ->export_data([], (object)$data);
    }

    /**
     * Delete all user data for a user.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        $DB->delete_records('report_feedback_tracker_duedates', ['userid' => $userid]);
        $DB->delete_records('report_feedback_tracker_lti_usr', ['userid' => $userid]);
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof context_system) {
            return;
        }

        $DB->delete_records('report_feedback_tracker_duedates');
        $DB->delete_records('report_feedback_tracker_lti_usr');
    }

    /**
     * Get users in a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        // Add users from report_feedback_tracker_duedates.
        $userids = $DB->get_fieldset_select('report_feedback_tracker_duedates', 'userid', 'userid IS NOT NULL');
        foreach ($userids as $userid) {
            $userlist->add_user($userid);
        }

        // Add users from report_feedback_tracker_lti_usr.
        $userids = $DB->get_fieldset_select('report_feedback_tracker_lti_usr', 'userid', 'userid IS NOT NULL');
        foreach ($userids as $userid) {
            $userlist->add_user($userid);
        }
    }

    /**
     * Delete data for multiple users.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }

        $userids = $userlist->get_userids();
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $DB->delete_records_select(
            'report_feedback_tracker_duedates',
            "userid $insql",
            $params
        );

        $DB->delete_records_select(
            'report_feedback_tracker_lti_usr',
            "userid $insql",
            $params
        );
    }
}
