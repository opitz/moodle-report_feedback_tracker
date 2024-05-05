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
 * Get the Feedback tracker data for all courses of a given user.
 *
 * @param stdClass $user
 * @return stdClass
 */
function get_feedback_tracker_data($courseid, $userid) {
    $data = new stdClass();
    $data->records = [];
    // Check if the user can edit a course.
    $data->showstudents = ($userid === null || is_course_editor($courseid, $userid)) ? true : false;

    if ($courseid) { // Show only grade items for the given course.
        $course = get_course($courseid);
        get_course_gradings($course, $userid, $data);
    } else { // Show all grade items of all enrolled courses.
        // Retrieve enrolled courses for the user.
        $enrolledcourses = enrol_get_users_courses($userid);

        foreach ($enrolledcourses as $course) {
            get_course_gradings($course, $userid, $data);
        }
    }
    return $data;
}

/**
 * Get the gradings for a course and amend the data with the findings.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $data
 * @return void
 * @throws dml_exception
 */
function get_course_gradings($course, $userid, &$data) {
    global $DB;

    $warningdays = 14; // The days before a date when a warning is shown. TODO: Make an admin option.
    $feedbackdeadlinedays = 30; // The days to provide feedback after the activity due date. TODO: Make an admin option.
    $feedbackextenddays = 7; // The days to provide feedback after the activity due date. TODO: Make an admin option.
    $oneday = 24 * 60 * 60; // Number of seconds in a day.
    $oneweek = 7 * $oneday; // Number of seconds in a week.
    $twoweeks = 2 * $oneweek; // Number of seconds in two weeks.
    $warningperiod = $warningdays * $oneday; // Number of seconds in the warning period.
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.
    $feedbackextendperiod = $feedbackextenddays * $oneday; // Number of seconds in the feedback period.

    $sql = "
    select
    #gi.*,
    gg.id,
    gi.courseid,
    gi.id as itemid,
    gi.itemname,
    gi.itemmodule,
    gi.iteminstance,
    u.id as studentid,
    u.username as student,
    gi.gradepass,
    gi.grademax,
    gg.finalgrade,
    gg.feedback,
    gg.timemodified as feedbackdate,
    cm.id as assignmentid,
    um.username as grader,
    gg.timemodified
    from {grade_items} gi 
    JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.course = gi.courseid AND cm.instance = gi.iteminstance
    JOIN {modules} m on m.name = gi.itemmodule AND m.id = cm.module
    left join {grade_grades} gg on gi.id = gg.itemid
    left join {user} u on u.id = gg.userid
    left join {user} um on um.id = gg.usermodified
    where 1
    and gi.courseid = $course->id
    and gi.itemtype = 'mod'
";

    if ($userid) {
//        $sql .= "and gg.userid = $userid";
    }
    $gradeitems = $DB->get_records_sql($sql);

    foreach ($gradeitems as $gradeitem) {

        // Check if the gradeitem module is supported.
        if (!module_is_supported($gradeitem)) {
            continue;
        }

        // If a user is given check if the user is allowed to access the grade item.
        if ($userid) {
            $capability = 'mod/' . $gradeitem->itemmodule . ':view';
            if (!has_capability($capability, context_module::instance($gradeitem->itemid))) {
                continue;
            }
            if($gradeitem->studentid && $gradeitem->studentid != $userid) {
                continue;
            }
        }

        // Get a submission due date if there is one.
        $module = get_module($gradeitem);
        $duedate = isset($module->duedate) ? $module->duedate : 0;
        // Calculate the feedback due date from the submission due date if there is one.
        $feedbackduedate = $duedate ? $duedate + $feedbackperiod : 0;
        // Get the submission date if any.
        if ($userid) {
            $submissiondate = get_submissiondate($userid, $gradeitem);
        } else {
            $submissiondate = get_submissiondate($gradeitem->studentid, $gradeitem);
        }

        // If there is feedback in the gradebook show a link to it.
        $link = '';
        if (isset($gradeitem->finalgrade) || isset($gradeitem->feedback)) {
            $link = html_writer::link("/mod/$gradeitem->itemmodule/view.php?id=$gradeitem->assignmentid", "view feedback");
        }

        $record = new stdClass();
        $record->submissiondate = $submissiondate == 0 ? '--' : date("Y-m-d", $submissiondate);
        $record->submissionstatus = get_submission_status($submissiondate, $duedate, $warningperiod);
        $record->submissiondatestatusclass = get_date_status_class($submissiondate, $warningperiod);
        $record->course = $course->shortname;
        $record->assessment = $gradeitem->itemname;
        $record->type = $gradeitem->itemmodule;
        $record->duedate = $duedate == 0 ? '--' : date("Y-m-d", $duedate);
        $record->duedatestatusclass = get_date_status_class($duedate, $warningperiod);
        $record->feedbackduedate = $feedbackduedate == 0 ? '--' : date("Y-m-d", $feedbackduedate);
        $record->feedbackstatus = $feedbackduedate == 0 ? '' : get_feedback_status($feedbackduedate, $feedbackperiod, $feedbackextendperiod, $gradeitem->feedbackdate, $gradeitem->finalgrade);
        $record->feedbackstatusclass = get_feedback_status_class($feedbackduedate, $warningperiod, $link);
        $record->grade = ($gradeitem->finalgrade ? (int)$gradeitem->finalgrade : '--') . '/' . (int)$gradeitem->grademax;
        $record->student = $gradeitem->student;
        $record->grader = $gradeitem->grader;
        $record->feedback = $link;

        $data->records[] = $record;
    }
}

function get_submission_status($submissiondate, $duedate, $warningperiod) {

    // Submission was in time.
    if ($submissiondate && $submissiondate <= $duedate) {
        return ' <i class="fa fa-check-square text-success"></i>';
    }

    // NO submission but approaching due date within warning period.
    if (!$submissiondate && time() <= $duedate && time() >= $duedate - $warningperiod) {
        return ' <i class="fa fa-exclamation-triangle text-warning"></i>';
    }

    // NO submission and the due date has passed.
    if (!$submissiondate && time() > $duedate ) {
        return ' <i class="fa fa-exclamation-circle text-danger"></i>';
    }

    // The submission is not due yet - so return nothing.
    return '';
}

function get_feedback_status($feedbackduedate, $feedbackperiod, $feedbackextendperiod, $feedbackdate, $finalgrade) {

    // Feedback was given in time.
    if (isset($finalgrade) && $feedbackdate <= $feedbackduedate) {
        return ' <i class="fa fa-check-square text-success"></i>';
    }

    $warningduedate = $feedbackduedate + $feedbackextendperiod;

    // Feedback was given within the extend period.
    if (isset($finalgrade) && $feedbackdate <= $warningduedate) {
        return ' <i class="fa fa-exclamation-triangle text-warning"></i>';
    }

    // Feedback was given outside the extend period.
    if (isset($finalgrade) && $feedbackdate > $warningduedate) {
        return ' <i class="fa fa-exclamation-circle text-danger"></i>';
    }

    // NO feedback was given but it's still within the extend period.
    if (!isset($finalgrade) && $feedbackduedate < time() && $warningduedate >= time() ) {
        return ' <i class="fa fa-exclamation-triangle text-warning"></i>';
    }

    // NO feedback was given, and it is beyond the extend period.
    if (!isset($finalgrade) && $warningduedate < time()) {
        return ' <i class="fa fa-exclamation-circle text-danger"></i>';
    }

    // The feedback is due within the due time - so do nothing.
    return '';
}

/**
 * Check if a module is supported.
 *
 * @param stdClass $gradeitem
 * @return bool
 */
function module_is_supported($gradeitem) {
    // Todo: make an admin option.
    $supportedmodules = [
        'assign',
#        'lesson',
        'turnitin',
        'quiz',
#        'workshop',
    ];

    if(in_array($gradeitem->itemmodule, $supportedmodules)) {
        return true;
    }
    return false;
}

/**
 * Get the submission date for a grade item and student if any.
 *
 * @param int $userid
 * @param stdClass $gradeitem
 * @return int // The submission date in seconds since 1.1.1970.
 * @throws dml_exception
 */
function get_submissiondate($userid, $gradeitem) {
    global $DB;

    $submissiondate = 0;

    if($gradeitem) {
        switch ($gradeitem->itemmodule) {
            case 'assign':
                $details = [
                    'table' => 'assign_submission',
                    'index' => 'assignment',
                    'user' => 'userid',
                    'date' => 'timemodified',
                    'status' => 'status',
                    ];
                break;
            case 'lesson':
                $details = [
                    'table' => 'lesson_attempts',
                    'index' => 'lessonid',
                    'user' => 'userid',
                    'date' => 'timeseen',
                    'status' => 'correct',
                    ];
                break;
            case 'quiz':
                $details = [
                    'table' => 'quiz_attempts',
                    'index' => 'quiz',
                    'user' => 'userid',
                    'date' => 'timefinish',
                    'status' => 'state',
                    ];
                break;
            case 'scorm':
                break;
            case 'workshop':
                $details = [
                    'table' => 'workshop_submissions',
                    'index' => 'workshopid',
                    'user' => 'authorid',
                    'date' => 'timemodified',
                    'status' => ''
                ];
                break;
            default:
                break;
        }
    }

    // Compute details.
    if (isset($details)) {
        if ($submissionrecord = $DB->get_record($details['table'],
            [$details['user'] => $userid, $details['index'] => $gradeitem->iteminstance]
        )) {
            $datefield = $details['date'];
            $submissiondate = $submissionrecord->$datefield;
        }
        unset($details);
    }
    return $submissiondate; // In seconds since 1.1.1970.
}


/**
 * Return the ability of a user to edit a course.
 *
 * @param stdClass $course
 * @param int $userid
 * @return bool
 * @throws coding_exception
 */
function is_course_editor($courseid, $userid) {
    if (!isset($courseid)) {
        return false;
    }
    $coursecontext = context_course::instance($courseid);
    if (has_capability('moodle/course:update', $coursecontext, $userid)) {
        return true;
    }
    return false;
}

/**
 * Check due date and return bootstrap class for background colour when date limits have been reached.
 *
 * @param int $duedate  // The due date in seconds since 1.1.1970.
 * @param int $difftime // The time difference in seconds for a warning period.
 * @return string
 */
function get_date_status_class($duedate, $difftime) {
    if ($duedate) {
        switch ($duedate) {
            case $duedate < time(): // Overdue.
                return "bg-danger";
            case $duedate < (time() + $difftime): // Due within difftime.
                return "bg-warning";
        }
    }
    return "";
}

function get_feedback_status_class($duedate, $difftime, $link = '') {
    if ($link != '') {
        return "bg-success";
    }
    if ($duedate) {
        switch ($duedate) {
            case $duedate < time(): // Overdue.
                return "bg-danger";
            case $duedate < (time() + $difftime): // Due within difftime.
                return "bg-warning";
        }
    }
    return "";
}

function get_grade($course, $cm, $user) {
    global $DB;
    $gradeitems = $DB->get_records('grade_items', []);
    $grade = new stdClass();

    $grade->state = 'finished';
    $grade->grade = 0.9;


    return $grade;
}
function get_grade0($course, $cm, $user) {
    global $DB;
    if (!$gradeitems = $DB->get_records('grade_items',
        ['iteminstance' => $cm->instance, 'itemmodule' => $cm->modname, 'courseid' => $course->id])) {
        return false;
    }
    $grade = new stdClass();

    $grade->state = 'finished';
    $grade->grade = 0.9;


    return $grade;
}



/**
 * Get information about the module instance.
 *
 * @param stdClass $gi
 * @return false|mixed|stdClass
 * @throws dml_exception
 */
function get_module($gi) {
    global $DB;

    // Handle cases of module types here where needed.
    switch ($gi->itemmodule) {
        case 'assign':
            $tablename = $gi->itemmodule;
            break;
        case 'lesson':
            $tablename = $gi->itemmodule;
            $replacements = ['deadline' => 'duedate'];
            break;
        case 'quiz':
            $tablename = $gi->itemmodule;
            $replacements = ['timeclose' => 'duedate'];
            break;
        case 'scorm':
            $tablename = $gi->itemmodule;
            $replacements = ['timeclose' => 'duedate'];
            break;
        case 'workshop':
            $tablename = $gi->itemmodule;
            $replacements = ['submissionend' => 'duedate'];
            break;
        case 'special':
            // Do something specific here.
            break;
        default:
            $tablename = $gi->itemmodule;
            break;
    }

    $module = $DB->get_record($tablename, ['id' => $gi->iteminstance]);

    // Compute replacement values.
    if (isset($replacements)) {
        foreach ($replacements as $from => $to) {
            $module->$to = $module->$from;
        }
        unset($replacement);
    }

    return $module;
}
function get_module0($cm) {
    global $DB;

    // Handle special cases of module types here where needed.
    switch ($cm->modname) {
        case 'assign':
            $tablename = $cm->modname;
            break;
        case 'quiz':
            $tablename = $cm->modname;
            $replacements = ['timeclose' => 'duedate'];
            break;
        case 'special':
            // Do something specific here.
            break;
        default:
            $tablename = $cm->modname;
            break;
    }

    $module = $DB->get_record($tablename, ['id' => $cm->instance]);

    if (isset($replacements)) {
        foreach ($replacements as $from => $to) {
            $module->$to = $module->$from;
        }
        unset($replacement);
    }

    return $module;
}

/**
 * Return some dummy data.
 *
 * @return stdClass
 */
function get_dummy_data() {
    // Get 10 lines of dummy data.
    $data = new stdClass();
    $data->records = [];

    $oneday = 24 * 60 * 60; // Number of seconds in a day.
    $oneweek = 7 * $oneday; // Number of seconds in a week.
    $twoweeks = 2 * $oneweek; // Number of seconds in two weeks.

    for ($i = 1; $i <= 10; $i++) {
        $record = new stdClass();
        $record->course = "Dummy Course";
        $record->assessment = "Dummy Assessment";
        $record->type = "dummy";
        $record->summative = "yes";
        $duedate = strtotime("-14 days");
        $record->duedate = date("Y-m-d", $duedate);
        $record->submissiondate = date("Y-m-d", $duedate - $oneday);
        $feedbackduedate = $duedate + $twoweeks;
        $record->feedbackduedate = date("Y-m-d", $feedbackduedate);
        $record->fullfeedback = "Full feedback here";
        $record->grade = "80/100";

        $data->records[] = $record;
    }

    return $data;
}
