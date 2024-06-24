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
 * This file contains public API of feedback_tracker report
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/report/feedback_tracker/locallib.php');

/**
 * Get the Feedback Tracker data for all enrolled users of a given course.
 *
 * @param int $courseid
 * @return stdClass
 */
function get_feedback_tracker_admin_data($courseid) {
    global $OUTPUT, $PAGE;

    $data = new stdClass();
    $data->records = [];

    // The filter options.
    $data->courseoptions = [];
    $data->typeoptions = [];
    $data->summativeoptions = [];
    $data->feedbackoptions = [];
    $data->methodoptions = [];

    // Get the students of the course.
    $sdata = new stdClass();
    $context = \context_course::instance($courseid);
    $users = get_enrolled_users($context);
    $sdata->students = [];
    foreach ($users as $user) {
        $sdata->students[] = $user;
    }

    // Render the drop down menu for switching into student view.
    $data->studentdd = $OUTPUT->render_from_template('report_feedback_tracker/studentdropdown', $sdata);

    // Check if the user is in edit mode.
    $data->editmode = $PAGE->user_is_editing();

    $course = get_course($courseid);
    // Get the gradings and append them to the data.
    get_admin_course_gradings($course, $data);

    return $data;
}

/**
 * Get the Feedback tracker data for one or all courses of a given user.
 *
 * @param int $userid
 * @param int $courseid
 * @return stdClass
 */
function get_feedback_tracker_user_data($userid, $courseid) {
    $data = new stdClass();
    $data->records = [];

    // The filter options.
    $data->courseoptions = [];
    $data->typeoptions = [];
    $data->summativeoptions = [];
    $data->feedbackoptions = [];
    $data->methodoptions = [];

    // If a course ID is given return data for that course only
    // otherwise return data for all courses a user is enrolled in.
    if ($courseid) {
        $course = get_course($courseid);
        get_user_course_gradings($course, $userid, $data);
    } else {
        $enrolledcourses = enrol_get_users_courses($userid);
        foreach ($enrolledcourses as $course) {
            get_user_course_gradings($course, $userid, $data);
        }
    }
    return $data;
}

/**
 * This function extends the course navigation with the report items
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass $course The course to object for the report
 * @param stdClass $context The context of the course
 */
function report_feedback_tracker_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('report/feedback_tracker:view', $context)) {
        $url = new moodle_url('/report/feedback_tracker/index.php', ['id' => $course->id]);
        $navigation->add(get_string('pluginname', 'report_feedback_tracker'),
            $url, navigation_node::TYPE_SETTING, null, null, new pix_icon('i/report', ''));
    }
}

/**
 * This function extends the course navigation with the report items
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass $user
 * @param stdClass $course The course to object for the report
 */
function report_feedback_tracker_extend_navigation_user($navigation, $user, $course) {
    global $USER;

    if (isguestuser() || !isloggedin()) {
        return;
    }

    if (\core\session\manager::is_loggedinas() || $USER->id != $user->id) {
        // No peeking at somebody else's sessions!
        return;
    }

    $context = context_course::instance($course->id);
    if (has_capability('report/feedback_tracker:view', $context) || true) {
        $navigation->add(get_string('navigationlink', 'report_feedback_tracker'),
            new moodle_url('/report/feedback_tracker/user.php'), $navigation::TYPE_SETTING);
    }
}

/**
 * Add nodes to myprofile page.
 *
 * @param \core_user\output\myprofile\tree $tree Tree object
 * @param stdClass $user user object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 *
 * @return bool
 */
function report_feedback_tracker_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $COURSE, $USER;

    if (isguestuser() || !isloggedin()) {
        return;
    }

    if (\core\session\manager::is_loggedinas() || $USER->id != $user->id) {
        // No peeking at somebody else's sessions!
        return;
    }

    $url = new moodle_url('/report/feedback_tracker/user.php', [
        'userid' => $user->id,
        'sesskey' => sesskey(),
    ]);

    $context = context_course::instance($COURSE->id);
    if (has_capability('report/feedback_tracker:view', $context) || true) {
        $node = new core_user\output\myprofile\node('reports', 'feedback_tracker',
            get_string('navigationlink', 'report_feedback_tracker'), null, new moodle_url($url));
        $tree->add_node($node);
    }
    return true;
}

/**
 * Is current user allowed to access this report
 *
 * @param stdClass $user
 * @param stdClass $course
 * @return bool
 */
function report_feedback_tracker_can_access_user_report($user, $course) {
    global $USER;

    $coursecontext = context_course::instance($course->id);
    $personalcontext = context_user::instance($user->id);

    if ($user->id == $USER->id) {
        if ($course->showreports && (is_viewing($coursecontext, $USER) || is_enrolled($coursecontext, $USER))) {
            return true;
        }
    } else if (has_capability('moodle/user:viewuseractivitiesreport', $personalcontext)) {
        if ($course->showreports && (is_viewing($coursecontext, $user) || is_enrolled($coursecontext, $user))) {
            return true;
        }
    }

    // Check if $USER shares group with $user (in case separated groups are enabled and 'moodle/site:accessallgroups' is disabled).
    if (!groups_user_groups_visible($course, $user->id)) {
        return false;
    }

    if (has_capability('report/feedback_tracker:viewuserreport', $coursecontext)) {
        return true;
    }

    return false;
}

/**
 * Callback to verify if the given instance of store is supported by this report or not.
 *
 * @param string $instance store instance.
 *
 * @return bool returns true if the store is supported by the report, false otherwise.
 */
function report_feedback_tracker_supports_logstore($instance) {
    if ($instance instanceof \core\log\sql_internal_table_reader) {
        return true;
    }
    return false;
}

/**
 * Callback to handle inplace editable items.
 *
 * @param string $itemtype
 * @param int $itemid
 * @param string $newvalue
 * @return \core\output\inplace_editable
 * @throws coding_exception
 * @throws dml_exception
 */
function report_feedback_tracker_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB, $PAGE;

    // Set the page context.
    $PAGE->set_context(context_system::instance());

    if (in_array($itemtype, ['method', 'generalfeedback', 'responsibility'])) {
        // If no record for this grade item exists, create it first.
        if (!$DB->get_record('report_feedback_tracker', ['gradeitem' => $itemid])) {
            $record = new stdClass();
            $record->gradeitem = $itemid;
            $DB->insert_record('report_feedback_tracker', $record);
        }

        // Update the database.
        $DB->set_field('report_feedback_tracker', $itemtype, $newvalue, ['gradeitem' => $itemid]);

        // Return the result.
        return new \core\output\inplace_editable(
            'report_feedback_tracker',
            $itemtype,
            $itemid,
            true,
            format_string($newvalue),
            $newvalue
        );
    }

    // The $itemtype is unknown - not good.
    throw new coding_exception('Unknown inplace editable type: ' . $itemtype);
}
