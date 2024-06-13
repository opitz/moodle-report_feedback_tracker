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

    $data->studentdd = $OUTPUT->render_from_template('report_feedback_tracker/studentdropdown', $sdata);

    // Check if the user is in edit mode.
    $data->editmode = $PAGE->user_is_editing();

    $course = get_course($courseid);
    get_admin_course_gradings($course, $data);
    return $data;
}

/**
 * Get the gradings for all users of a course and amend the data with the findings.
 *
 * @param stdClass $course
 * @param stdClass $data
 * @return void
 * @throws dml_exception
 */
function get_admin_course_gradings($course, &$data) {
    global $DB;

    $sql = "
    select
        ROW_NUMBER() OVER (ORDER BY gi.id) AS uniqueid,
        gi.courseid,
        gi.id as itemid,
        gi.itemname,
        gi.itemtype,
        gi.itemmodule,
        cm.id as assignmentid,
        cm.visible,
        gi.iteminstance,
        gi.gradepass,
        gi.grademax,
        CASE
            WHEN gi.itemmodule = 'assign' THEN
                (select duedate from {assign} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'lesson' THEN
                (select deadline from {lesson} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'quiz' THEN
                (select timeclose from {quiz} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'scorm' THEN
                (select timeclose from {scorm} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'turnitintooltwo' THEN
                0
            WHEN gi.itemmodule = 'workshop' THEN
                (select submissionend from {workshop} where id = gi.iteminstance)
            ELSE 0
        END as duedate,
        (select count(distinct gg.userid) from {grade_grades} gg where gg.itemid = gi.id and gg.finalgrade != '') as feedbacks,
        CASE
            WHEN gi.itemmodule = 'assign' THEN
                (select count(distinct asu.userid) from {assign_submission} asu
                                                   where asu.assignment = gi.iteminstance and asu.status = 'submitted')
            WHEN gi.itemmodule = 'lesson' THEN
                (select count(distinct la.userid) from {lesson_attempts} la
                                                  where la.lessonid = gi.iteminstance)
            WHEN gi.itemmodule = 'quiz' THEN
                (select count(distinct qa.userid) from {quiz_attempts} qa
                                                  where qa.quiz = gi.iteminstance and qa.state = 'finished')
            WHEN gi.itemmodule = 'scorm' THEN
                (select count(distinct sa.userid) from {scorm_attempt} sa
                                                  where sa.scormid = gi.iteminstance)
            WHEN gi.itemmodule = 'turnitintooltwo' THEN
                (select count(distinct ts.userid) from {turnitintooltwo_submissions} ts
                                                  where ts.turnitintooltwoid = gi.iteminstance and ts.submission_type = 1)
            WHEN gi.itemmodule = 'workshop' THEN
                (select count(distinct ws.authorid) from {workshop_submissions} ws where ws.workshopid = gi.iteminstance)
            ELSE '--'
        END as submissions,
        rft.summative,
        rft.hidden,
        rft.feedbackduedate,
        rft.method,
        rft.responsibility,
        rft.generalfeedback,
        rft.gfurl
    from {grade_items} gi
        left JOIN {modules} m on m.name = gi.itemmodule
        left JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.course = gi.courseid AND m.id = cm.module
        left JOIN {report_feedback_tracker} rft on rft.gradeitem = gi.id
    where gi.courseid = :courseid
";
    $params['courseid'] = $course->id;
    $gradeitems = $DB->get_records_sql($sql, $params);

    foreach ($gradeitems as $gradeitem) {
        // Check if the gradeitem module is supported.
        if (!module_is_supported($gradeitem)) {
            continue;
        }

        // All good - now get and store the feedback record.
        // TurnitinToolTwo special treatment as one grading item may have several parts.
        if ($gradeitem->itemmodule == 'turnitintooltwo') {
            get_admin_turnitin_records($course, $gradeitem, $data);
        } else {
            $record = get_admin_feedback_record($course, $gradeitem);
            $data->records[] = $record;
        }
    }

    // Get the filter options where available.
    get_admin_filter_options($data);
}

/**
 * Get the admin feedback record for a grade item.
 *
 * @param stdClass $course
 * @param stdClass $gradeitem
 * @return stdClass
 * @throws dml_exception
 */
function get_admin_feedback_record ($course, $gradeitem) {
    global $PAGE;

    $oneday = 24 * 60 * 60; // Number of seconds in a day.
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.

    $record = new stdClass();
    $record->course = get_course_link($course);
    $record->courseid = $course->id;
    $record->coursename = $course->shortname;
    $record->assessment = get_item_link($gradeitem);
    $record->type = get_item_type($gradeitem);
    $record->module = get_item_module($gradeitem);
    $record->duedate = $gradeitem->duedate == 0 ? '--' : date("d/m/Y", $gradeitem->duedate);
    $record->feedbackduedate = render_feedbackduedate($gradeitem, $feedbackperiod);
    $record->feedbacks = get_feedbacks($gradeitem);
    $record->method = get_feedback_method($gradeitem);
    $record->responsibility = get_feedback_responsibility($gradeitem);
    $record->generalfeedback = get_admin_generalfeedback($gradeitem);
    $record->gfurl = $gradeitem->gfurl;
    $record->summative = get_summative_state($gradeitem);
    $record->summativetext = $gradeitem->summative ? get_string('summative', 'report_feedback_tracker') : "";
    $record->hidden = get_hidden_state($gradeitem);

    return $record;
}

/**
 * Get the parts of a turnitintooltwo grading item and list them as separate items.
 *
 * @param stdClass $course
 * @param stdClass $gradeitem
 * @param stdClass $data
 * @return void
 * @throws dml_exception
 */
function get_admin_turnitin_records($course, $gradeitem, &$data) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.

    // Get the parts.
    $tttparts = get_tttparts($gradeitem);

    // Make each part a record and store it in the data.
    foreach ($tttparts as $tttpart) {
        $duedate = $tttpart->dtdue; // Each part may have its own due date.
        // If there is a manual feedback due date use it, otherwise calculate it from the submission due date.
        $feedbackduedate = $gradeitem->feedbackduedate ?? ($duedate ? $duedate + $feedbackperiod : 0);

        $record = new stdClass();
        $record->course = get_course_link($course);
        $record->courseid = $course->id;
        $record->coursename = $course->shortname;
        $record->assessment = get_item_link($gradeitem, $tttpart->partname);
        $record->type = get_item_type($gradeitem);
        $record->module = get_item_module($gradeitem);
        $record->duedate = $duedate == 0 ? '--' : date("d. M Y", $duedate);
        $record->feedbackduedate = $feedbackduedate == 0 ? '--' : date("d. M Y", $feedbackduedate);
        $record->feedbacks = get_feedbacks($gradeitem);
        $record->method = get_feedback_method($gradeitem);
        $record->responsibility = get_feedback_responsibility($gradeitem);
        $record->generalfeedback = get_admin_generalfeedback($gradeitem);
        $record->gfurl = $gradeitem->gfurl;
        $record->summative = get_summative_state($gradeitem);
        $record->summativetext = $gradeitem->summative ? get_string('summative', 'report_feedback_tracker') : "";
        $record->hidden = get_hidden_state($gradeitem);
        $data->records[] = $record;
    }
}

/**
 * Get the options for filtering the admin table.
 *
 * @param stdClass $data
 * @return void
 */
function get_admin_filter_options(&$data) {
    foreach ($data->records as $record) {

        // Course options.
        if ($record->courseid) {
            $option = new stdClass();
            $option->key = $record->courseid;
            $option->value = $record->coursename;
            if (!in_array($option, $data->courseoptions)) {
                $data->courseoptions[] = $option;
            }
        }

        // Method options.
        if ($record->method) {
            $option = new stdClass();
            $option->key = $record->method;
            $option->value = $record->method;
            if (!in_array($option, $data->methodoptions)) {
                $data->methodoptions[] = $option;
            }
        }

        // Summative / formative options.
        if ($record->summative) {
            $option = new stdClass();
            $option->key = $record->summativetext;
            $option->value = $record->summativetext;
            if (!in_array($option, $data->summativeoptions)) {
                $data->summativeoptions[] = $option;
            }
        }

        // Type (module) options.
        if ($record->module) {
            $option = new stdClass();
            $option->key = $record->module;
            $option->value = $record->module;
            if (!in_array($option, $data->typeoptions)) {
                $data->typeoptions[] = $option;
            }
        }

    }

}

/**
 * Show / edit the feedback method for a grade item.
 *
 * @param stdClass $gradeitem
 * @return string
 * @throws coding_exception
 */
function get_feedback_method($gradeitem) {
    global $OUTPUT, $PAGE;

    if ($PAGE->user_is_editing()) {
        $edititem = new \core\output\inplace_editable(
            'report_feedback_tracker',
            'method',
            $gradeitem->itemid,
            true,
            format_string($gradeitem->method),
            $gradeitem->method,
            get_string('edit:method', 'report_feedback_tracker')
        );
        return html_writer::div($OUTPUT->render($edititem));
    }
    return $gradeitem->method;
}

/**
 * Show/edit the responsibility of a grade item.
 *
 * @param stdClass $gradeitem
 * @return string
 * @throws coding_exception
 */
function get_feedback_responsibility($gradeitem) {
    global $OUTPUT, $PAGE;

    if ($PAGE->user_is_editing()) {
        $edititem = new \core\output\inplace_editable(
            'report_feedback_tracker',
            'responsibility',
            $gradeitem->itemid,
            true,
            format_string($gradeitem->responsibility),
            $gradeitem->responsibility,
            get_string('edit:responsibility', 'report_feedback_tracker')
        );
        return html_writer::div($OUTPUT->render($edititem));
    }
    return html_writer::div($gradeitem->responsibility);
}

/**
 * Show/edit the general feedback for a grade item.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_admin_generalfeedback($gradeitem) {
    global $OUTPUT, $PAGE;

    $o = html_writer::start_div('generalfeedback');
    if ($PAGE->user_is_editing()) {
        $o .= html_writer::span($gradeitem->generalfeedback, 'generalfeedbacktext',
            ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);

        $o .= ' ' . html_writer::tag('i', '',
            [
                'id' => html_writer::random_id('generalfeedback'),
                'class' => 'icon fa fa-pencil fa-fw',
                'cmid' => $gradeitem->itemid,
                'data-action' => 'report_feedback_tracker/showgeneralfeedback',
                'data-generalfeedback' => $gradeitem->generalfeedback,
                'data-gfurl' => $gradeitem->gfurl,
            ]);
    } else {
        $o .= html_writer::div($gradeitem->generalfeedback, 'generalfeedbacktext',
            ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);
    }
    $o .= html_writer::end_div();
    return $o;
}

/**
 * Show the general feedback and the gf URL to students.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_user_generalfeedback($gradeitem) {

    $o = html_writer::start_div('generalfeedback');
    $o .= html_writer::div($gradeitem->generalfeedback, 'generalfeedbacktext',
        ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);
    $link = "<a href='$gradeitem->gfurl'>$gradeitem->gfurl</a>";
    $o .= html_writer::div($link, 'gfurl',
        ['id' => 'gfurl_' . $gradeitem->itemid]);

    $o .= html_writer::end_div();
    return $o;
}

/**
 * Get the Feedback Tracker data for all courses of a given user.
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
 * Get the gradings for a single course user and amend the data with the findings.
 *
 * @param stdClass $course
 * @param int $userid
 * @param stdClass $data
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 */
function get_user_course_gradings($course, $userid, stdClass &$data) {
    global $DB;

    $sql = "
    select
        ROW_NUMBER() OVER (ORDER BY gi.id) AS uniqueid,
        gi.courseid,
        gi.id as itemid,
        gi.itemname,
        gi.itemtype,
        gi.itemmodule,
        gi.iteminstance,
        u.id as studentid,
        u.username as student,
        gi.gradepass,
        gi.grademax,
        CASE
            WHEN gi.itemmodule = 'assign' THEN
                (select duedate from {assign} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'lesson' THEN
                (select deadline from {lesson} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'quiz' THEN
                (select timeclose from {quiz} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'scorm' THEN
                (select timeclose from {scorm} where id = gi.iteminstance)
            WHEN gi.itemmodule = 'turnitintooltwo' THEN
                0
            WHEN gi.itemmodule = 'workshop' THEN
                (select submissionend from {workshop} where id = gi.iteminstance)
            ELSE 0
        END as duedate,
        gg.finalgrade,
        gg.feedback,
        gg.timemodified as feedbackdate,
        cm.id as assignmentid,
        cm.visible,
        um.username as grader,
        gg.timemodified,
        rft.summative,
        rft.hidden,
        rft.feedbackduedate,
        rft.method,
        rft.responsibility,
        rft.generalfeedback,
        rft.gfurl
    from {grade_items} gi
        left JOIN {modules} m on m.name = gi.itemmodule
        left JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.course = gi.courseid AND m.id = cm.module
        left JOIN {report_feedback_tracker} rft on rft.gradeitem = gi.id
        left join {grade_grades} gg on gi.id = gg.itemid and gg.userid = :userid
        left join {user} u on u.id = gg.userid
        left join {user} um on um.id = gg.usermodified
    where gi.courseid = :courseid
";

    $params['courseid'] = $course->id;
    $params['userid'] = $userid;
    $gradeitems = $DB->get_records_sql($sql, $params);

    foreach ($gradeitems as $gradeitem) {
        // Check if the gradeitem module is supported.
        if (!module_is_supported($gradeitem)) {
            continue;
        }

        // Check if a user is allowed to access the grade item.
        if ($userid) {
            if ($gradeitem->itemmodule) {
                $capability = 'mod/' . $gradeitem->itemmodule . ':view';
                if (!has_capability($capability, context_module::instance($gradeitem->assignmentid))) {
                    continue;
                }
            }
        }

        // All good - now get and store the feedback record.
        // TurnitinToolTwo special treatment as one grading item may have several parts.
        if ($gradeitem->itemmodule == 'turnitintooltwo') {
            get_user_turnitin_records($course, $gradeitem, $userid, $data);
        } else {
            $record = get_user_feedback_record($course, $userid, $gradeitem);
            $data->records[] = $record;
        }
    }

    // Get the filter options where available.
    get_user_filter_options($data);
}

/**
 * Get the user feedback record for a grade item.
 *
 * @param stdClass $course
 * @param int $userid
 * @param stdClass $gradeitem
 * @return stdClass
 * @throws dml_exception
 */
function get_user_feedback_record($course, $userid, $gradeitem) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.

    $warningdays = get_config('report_feedback_tracker', 'warningdays');
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackextenddays = get_config('report_feedback_tracker', 'feedbackextenddays');
    $warningperiod = $warningdays * $oneday; // Number of seconds in the warning period.
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.
    $feedbackextendperiod = $feedbackextenddays * $oneday; // Number of seconds in the feedback period.

    // If there is a manual feedback due date use it, otherwise calculate it from the submission due date.
    $feedbackduedate = $gradeitem->feedbackduedate ? $gradeitem->feedbackduedate :
        ($gradeitem->duedate ? $gradeitem->duedate + $feedbackperiod : 0);
    // Get the submission date if any.
    $submissiondate = get_submissiondate($userid, $gradeitem);

    $record = new stdClass();
    $record->submissiondate = $submissiondate == 0 ? '--' : date("d. M Y", $submissiondate);
    $record->submissionstatus = get_submission_status($submissiondate, $gradeitem->duedate, $warningperiod);
    $record->course = get_course_link($course);
    $record->courseid = $course->id;
    $record->coursename = $course->shortname;
    $record->assessment = get_item_link($gradeitem);
    $record->type = get_item_type($gradeitem);
    $record->module = get_item_module($gradeitem);
    $record->summative = $gradeitem->summative ? get_string('summative', 'report_feedback_tracker') : "";
    $record->duedate = $gradeitem->duedate == 0 ? '--' : date("d. M Y", $gradeitem->duedate);
    $record->feedbackduedate = $feedbackduedate == 0 ? '--' : date("d. M Y", $feedbackduedate);
    $record->grade = ($gradeitem->finalgrade ? (int)$gradeitem->finalgrade : '--') . '/' . (int)$gradeitem->grademax;
    $record->student = $gradeitem->student;
    $record->grader = $gradeitem->grader;
    $record->feedbackdate = $gradeitem->feedbackdate;
    $record->feedbackstatus = ($submissiondate == 0) ? '' :
        get_feedback_status($gradeitem, $feedbackduedate, $feedbackextendperiod);
    $record->feedback = ($submissiondate == 0) ? '' :
        get_feedback_badge($gradeitem, $feedbackduedate, $feedbackextendperiod);
    $record->method = $gradeitem->method;
    $record->responsibility = html_writer::div($gradeitem->responsibility);
    $record->generalfeedback = get_user_generalfeedback($gradeitem);
    $record->gfurl = $gradeitem->gfurl;

    return $record;
}

/**
 * Get the options for filtering the user table.
 *
 * @param stdClass $data
 * @return void
 */
function get_user_filter_options(&$data) {
    foreach ($data->records as $record) {

        // Course options.
        if ($record->courseid) {
            $option = new stdClass();
            $option->key = $record->courseid;
            $option->value = $record->coursename;
            if (!in_array($option, $data->courseoptions)) {
                $data->courseoptions[] = $option;
            }
        }

        // Feedback options.
        if ($record->feedbackstatus) {
            $option = new stdClass();
            $option->key = $record->feedbackstatus;
            $option->value = $record->feedbackstatus;
            if (!in_array($option, $data->feedbackoptions)) {
                $data->feedbackoptions[] = $option;
            }
        }

        // Method options.
        if ($record->method) {
            $option = new stdClass();
            $option->key = $record->method;
            $option->value = $record->method;
            if (!in_array($option, $data->methodoptions)) {
                $data->methodoptions[] = $option;
            }
        }

        // Summative / formative options.
        if ($record->summative) {
            $option = new stdClass();
            $option->key = $record->summative;
            $option->value = $record->summative;
            if (!in_array($option, $data->summativeoptions)) {
                $data->summativeoptions[] = $option;
            }
        }

        // Type (module) options.
        if ($record->module) {
            $option = new stdClass();
            $option->key = $record->module;
            $option->value = $record->module;
            if (!in_array($option, $data->typeoptions)) {
                $data->typeoptions[] = $option;
            }
        }

    }

}

/**
 * Get the parts of a turnitintooltwo grading item and list them as separate items.
 *
 * @param stdClass $course
 * @param stdClass $gradeitem
 * @param int $userid
 * @param stdClass $data
 * @return void
 * @throws dml_exception
 */
function get_user_turnitin_records($course, $gradeitem, $userid, &$data) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.

    $warningdays = get_config('report_feedback_tracker', 'warningdays');
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackextenddays = get_config('report_feedback_tracker', 'feedbackextenddays');
    $warningperiod = $warningdays * $oneday; // Number of seconds in the warning period.
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.
    $feedbackextendperiod = $feedbackextenddays * $oneday; // Number of seconds in the feedback period.

    // Get the parts.
    $tttparts = get_tttparts($gradeitem);

    // Make each part a record and store it in the data.
    foreach ($tttparts as $tttpart) {
        $duedate = $tttpart->dtdue; // Each part may have its own due date.
        // If there is a manual feedback due date use it, otherwise calculate it from the submission due date.
        $feedbackduedate = $gradeitem->feedbackduedate ?? ($duedate ? $duedate + $feedbackperiod : 0);
        // Get the submission date if any.
        $submissiondate = get_ttt_submission_date($tttpart, $userid);

        $record = new stdClass();
        $record->submissiondate = $submissiondate == 0 ? '--' : date("d. M Y", $submissiondate);
        $record->submissionstatus = get_submission_status($submissiondate, $duedate, $warningperiod);
        $record->course = get_course_link($course);
        $record->courseid = $course->id;
        $record->coursename = $course->shortname;
        $record->assessment = get_item_link($gradeitem, $tttpart->partname);
        $record->type = get_item_type($gradeitem);
        $record->module = get_item_module($gradeitem);
        $record->summative = $gradeitem->summative ? get_string('summative', 'report_feedback_tracker') : "";
        $record->duedate = $duedate == 0 ? '--' : date("d. M Y", $duedate);
        $record->feedbackduedate = $feedbackduedate == 0 ? '--' : date("d. M Y", $feedbackduedate);
        $record->grade = ($gradeitem->finalgrade ? (int)$gradeitem->finalgrade : '--') . '/' . (int)$gradeitem->grademax;
        $record->student = $gradeitem->student;
        $record->grader = $gradeitem->grader;
        $record->feedbackstatus = ($submissiondate == 0) ? '' :
            get_feedback_status($gradeitem, $feedbackduedate, $feedbackextendperiod);
        $record->feedback = ($submissiondate == 0) ? '' :
            get_feedback_badge($gradeitem, $feedbackduedate, $feedbackextendperiod);
        $record->method = $gradeitem->method;

        $data->records[] = $record;
    }
}

/**
 * Get the submission date for a ttt part.
 *
 * NOTE: This actually returns the modification date of a submission - which is also updated when
 * a submission is graded - there is no separate submission date to use :(.
 *
 * @param stdClass $tttpart
 * @param int $userid
 * @return mixed
 * @throws dml_exception
 */
function get_ttt_submission_date($tttpart, $userid) {
    global $DB;

    if ($res = $DB->get_record('turnitintooltwo_submissions', ['submission_part' => $tttpart->id, 'userid' => $userid])) {
        return $res->submission_modified;
    }
    return 0;
}

/**
 * Get the parts of a turnitintooltwo assessment.
 *
 * @param stdClass $gradeitem
 * @return array
 * @throws dml_exception
 */
function get_tttparts($gradeitem) {
    global $DB;

    return $DB->get_records('turnitintooltwo_parts', ['turnitintooltwoid' => $gradeitem->iteminstance]);
}

/**
 * Get the feedbacks and submissions.
 *
 * @param stdClass $gradeitem
 * @return string
 * @throws dml_exception
 */
function get_feedbacks($gradeitem) {
    return $gradeitem->assignmentid ? html_writer::div("$gradeitem->feedbacks of $gradeitem->submissions") : '';
}

/**
 * Render a date picker when in edit mode, return the date otherwise.
 *
 * @param stdClass $gradeitem
 * @param int $feedbackperiod days of feedback extend period (yellow status) in seconds.
 * @return string
 * @throws coding_exception
 */
function render_feedbackduedate($gradeitem, $feedbackperiod = 0) {
    global $PAGE;

    // Use a stored feedback due date if present, otherwise
    // calculate the feedback due date from the submission due date if there is one.
    $date = $gradeitem->feedbackduedate ? $gradeitem->feedbackduedate :
        ($gradeitem->duedate ? $gradeitem->duedate + $feedbackperiod : 0);

    $o = '';
    if ($PAGE->user_is_editing()) { // Render a date picker.
        // Default to current date if not specified.
        $date = isset($date) && $date > 0 ? $date : time();
        // Generate a unique ID for the date picker input field.
        $pickerid = html_writer::random_id('date_picker');

        // Generate the input field with the unique ID.
        $inputfield = html_writer::empty_tag('input', [
            'type' => 'date',
            'id' => $pickerid,
            'itemid' => $gradeitem->itemid,
            'class' => 'date-picker',
            'data-action' => 'report_feedback_tracker/datepicker',
            'value' => date('Y-m-d', $date),
        ]);

        $o .= $inputfield;
    } else { // Just return the date.
        $o .= $date ? date("d/m/Y", $date) : '--';
    }

    if ($date) {
        $classes = 'fa fa-info-circle text-primary';
        $style = $gradeitem->feedbackduedate ? '' : 'display: none;';
        $title = get_string('feedbackduedate:custom', 'report_feedback_tracker');
        $o .= " <i class='$classes' title='$title' data-itemid='$gradeitem->itemid'
                data-action='report_feedback_tracker/customhint' style='$style'></i>";
    }

    return $o;
}

/**
 * Show / edit the summative state of a grading item.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_summative_state($gradeitem) {
    global $PAGE;

    if ($PAGE->user_is_editing()) {
        if ($gradeitem->summative) {
            return "<input
                data-action='report_feedback_tracker/summative_checkbox'
                type='checkbox'
                class='form-check-input'
                cmid='$gradeitem->itemid'
                checked='checked'
            >";
        } else {
            return "<input
                data-action='report_feedback_tracker/summative_checkbox'
                type='checkbox'
                class='form-check-input'
                cmid='$gradeitem->itemid'
            >";
        }
    } else {
        return $gradeitem->summative ? "<i class='fa fa-check'></i>" : '';
    }
}

/**
 * Show / edit the hiding state of a grading item.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_hidden_state($gradeitem) {
    global $PAGE;

    if ($PAGE->user_is_editing()) {
        if ($gradeitem->hidden) {
            return "<input
                data-action='report_feedback_tracker/hiding_checkbox'
                type='checkbox'
                class='form-check-input'
                cmid='$gradeitem->itemid'
                checked='checked'
            >";
        } else {
            return "<input
                data-action='report_feedback_tracker/hiding_checkbox'
                type='checkbox'
                class='form-check-input'
                cmid='$gradeitem->itemid'
            >";
        }
    } else {
        if ($gradeitem->hidden) {
            return "<i class='fa fa-check'></i>";
        } else {
            return '';
        }
    }
}

/**
 * Return an icon for a module type where available.
 *
 * @param stdClass $gradeitem
 * @return mixed|string
 */
function get_item_type($gradeitem) {
    global $CFG;

    $path = '';
    switch ($gradeitem->itemmodule) {
        case 'assign':
            $path = "$CFG->wwwroot/mod/assign/pix/monologo.svg";
            $title = get_string('pluginname', 'mod_assign');
            break;
        case 'lesson':
            $path = "$CFG->wwwroot/mod/lesson/pix/monologo.svg";
            $title = get_string('pluginname', 'mod_lesson');
            break;
        case 'quiz':
            $path = "$CFG->wwwroot/mod/quiz/pix/monologo.svg";
            $title = get_string('pluginname', 'mod_quiz');
            break;
        case 'turnitintooltwo':
            $path = "$CFG->wwwroot/mod/turnitintooltwo/pix/icon.png";
            $title = get_string('pluginname', 'mod_turnitintooltwo');
            break;
        case 'scorm':
            $path = "$CFG->wwwroot/mod/scorm/pix/monologo.svg";
            $title = get_string('pluginname', 'mod_scorm');
            break;
        case 'workshop':
            $path = "$CFG->wwwroot/mod/workshop/pix/monologo.svg";
            $title = get_string('pluginname', 'mod_workshop');
             break;
        default:
            return $gradeitem->itemmodule;
            break;
    }

    return "<img src='$path' alt='$gradeitem->itemmodule' title=$title>";

}

/**
 * Get the module name for a grade item.
 *
 * @param stdClass $gradeitem
 * @return lang_string|string
 * @throws coding_exception
 */
function get_item_module($gradeitem) {
    switch ($gradeitem->itemmodule) {
        case 'assign':
            return get_string('pluginname', 'mod_assign');
        case 'lesson':
            return get_string('pluginname', 'mod_lesson');
        case 'quiz':
            return get_string('pluginname', 'mod_quiz');
        case 'turnitintooltwo':
            return get_string('pluginname', 'mod_turnitintooltwo');
        case 'scorm':
            return get_string('pluginname', 'mod_scorm');
        case 'workshop':
            return get_string('pluginname', 'mod_workshop');
        default:
            return "";
    }
}

/**
 * Return a link to the course.
 *
 * @param stdClass $course
 * @return string
 */
function get_course_link($course) {
    global $CFG;

    return html_writer::link("$CFG->wwwroot/course/view.php?id=$course->id", $course->shortname);
}

/**
 * Return a link to the module item where applicable.
 *
 * @param stdClass $gradeitem
 * @param string $partname
 * @return mixed|string
 */
function get_item_link($gradeitem, $partname = '') {
    global $CFG, $USER;

    if (!isset($gradeitem->assignmentid)) {
        $url = "$CFG->wwwroot/grade/report/user/index.php?id=$gradeitem->courseid&userid=$USER->id";
    } else {
        $url = "$CFG->wwwroot/mod/$gradeitem->itemmodule/view.php?id=$gradeitem->assignmentid";
    }
    $linktext = $partname ? "$gradeitem->itemname - $partname" : $gradeitem->itemname;
    return html_writer::link($url, $linktext);
}

/**
 * Get a submission status icon.
 *
 * @param int $submissiondate
 * @param int $duedate
 * @param int $warningperiod
 * @return string
 */
function get_submission_status($submissiondate, $duedate, $warningperiod) {

    // Submission was in time.
    if ($submissiondate && $submissiondate <= $duedate) {
        $title = get_string('submission:success', 'report_feedback_tracker');
        return " <i class='fa fa-check-square text-success fa-2x' title='$title'></i>";
    }

    // Submission was late.
    if ($duedate && $submissiondate && $submissiondate > $duedate) {
        $title = get_string('submission:late', 'report_feedback_tracker');
        return " <i class='fa fa-check-square text-danger fa-2x' title='$title'></i>";
    }

    // NO submission but approaching due date within warning period.
    if (!$submissiondate && time() <= $duedate && time() >= $duedate - $warningperiod) {
        $title = get_string('submission:warning', 'report_feedback_tracker');
        return " <i class='fa fa-exclamation-triangle text-warning fa-2x' title='$title'></i>";
    }

    // NO submission and the due date has passed.
    if ($duedate && !$submissiondate && time() > $duedate ) {
        $title = get_string('submission:overdue', 'report_feedback_tracker');
        return " <i class='fa fa-exclamation-circle text-danger fa-2x' title='$title'></i>";
    }

    // The submission is not due yet - so return nothing.
    return '';
}

/**
 * Get a feedback badge.
 *
 * @param stdClass $gradeitem
 * @param int $feedbackduedate
 * @param int $feedbackextendperiod
 * @return string
 * @throws coding_exception
 */
function get_feedback_badge($gradeitem, $feedbackduedate, $feedbackextendperiod) {
    $contact = $gradeitem->responsibility;
    // Final grade is available even if there is no due date.
    if (!$feedbackduedate && isset($gradeitem->finalgrade)) {
        $o = html_writer::div(get_string('finalgrade_available', 'report_feedback_tracker'),
            "badge badge-pill badge-success");

        if ($contact) {
            $o .= html_writer::start_div('feedback_tracker_contact');
            $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
                ': ', 'feedback_tracker_contact_title');
            $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
            $o .= html_writer::end_div();
        }
        return $o;
    }

    // Feedback was given in time.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate <= $feedbackduedate) {
        $o = html_writer::div(get_string('feedback:in_time', 'report_feedback_tracker'),
            "badge badge-pill badge-success");
        if ($contact) {
            $o .= html_writer::start_div('feedback_tracker_contact');
            $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
                ': ', 'feedback_tracker_contact_title');
            $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
            $o .= html_writer::end_div();
        }
        return $o;
    }

    $warningduedate = $feedbackduedate + $feedbackextendperiod;

    // Feedback was given within the extended period.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate <= $warningduedate) {
        $o = html_writer::div(get_string('feedback:extended', 'report_feedback_tracker'),
            "badge badge-pill badge-warning");
        if ($contact) {
            $o .= html_writer::start_div('feedback_tracker_contact');
            $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
                ': ', 'feedback_tracker_contact_title');
            $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
            $o .= html_writer::end_div();
        }
        return $o;
    }

    // Feedback was given outside the extended period.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate > $warningduedate) {
        $o = html_writer::div(get_string('feedback:late', 'report_feedback_tracker'),
            "badge badge-pill badge-danger");
        if ($contact) {
            $o .= html_writer::start_div('feedback_tracker_contact');
            $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
                ': ', 'feedback_tracker_contact_title');
            $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
            $o .= html_writer::end_div();
        }
        return $o;
    }

    // NO feedback was given but it's still within the extended period.
    if (!isset($gradeitem->finalgrade) && $feedbackduedate < time() && $warningduedate >= time() ) {
        $o = html_writer::div(get_string('feedback:due', 'report_feedback_tracker'),
            "badge badge-pill badge-warning");
        if ($contact) {
            $o .= html_writer::start_div('feedback_tracker_contact');
            $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
                ': ', 'feedback_tracker_contact_title');
            $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
            $o .= html_writer::end_div();
        }
        return $o;
    }

    // NO feedback was given, and it is beyond the extended period.
    if (!isset($gradeitem->finalgrade) && $warningduedate < time()) {
        $o = html_writer::div(get_string('feedback:overdue', 'report_feedback_tracker'),
            "badge badge-pill badge-danger");
        if ($contact) {
            $o .= html_writer::start_div('feedback_tracker_contact');
            $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
                ': ', 'feedback_tracker_contact_title');
            $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
            $o .= html_writer::end_div();
        }
        return $o;
    }

    // The feedback is due within the due time - so do nothing and show a contact.
    $o = '';
    if ($contact) {
        $o .= html_writer::start_div('feedback_tracker_contact');
        $o .= html_writer::span(get_string('contact', 'report_feedback_tracker') .
            ': ', 'feedback_tracker_contact_title');
        $o .= html_writer::span($contact, 'feedback_tracker_contact_body');
        $o .= html_writer::end_div();
    }
    return $o;
}

/**
 * Get a feedback status.
 * @param stdClass $gradeitem
 * @param int $feedbackduedate
 * @param int $feedbackextendperiod
 * @return lang_string|string
 * @throws coding_exception
 */
function get_feedback_status($gradeitem, $feedbackduedate, $feedbackextendperiod) {
    $contact = $gradeitem->responsibility;
    // Final grade is available even if there is no due date.
    if (!$feedbackduedate && isset($gradeitem->finalgrade)) {
        return get_string('finalgrade_available', 'report_feedback_tracker');
    }

    // Feedback was given in time.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate <= $feedbackduedate) {
        return get_string('feedback:in_time', 'report_feedback_tracker');
    }

    $warningduedate = $feedbackduedate + $feedbackextendperiod;

    // Feedback was given within the extended period.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate <= $warningduedate) {
        return get_string('feedback:extended', 'report_feedback_tracker');
    }

    // Feedback was given outside the extended period.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate > $warningduedate) {
        return get_string('feedback:late', 'report_feedback_tracker');
    }

    // NO feedback was given but it's still within the extended period.
    if (!isset($gradeitem->finalgrade) && $feedbackduedate < time() && $warningduedate >= time() ) {
        return get_string('feedback:due', 'report_feedback_tracker');
    }

    // NO feedback was given, and it is beyond the extended period.
    if (!isset($gradeitem->finalgrade) && $warningduedate < time()) {
        return get_string('feedback:overdue', 'report_feedback_tracker');
    }

    // The feedback is due within the due time - so do nothing and show a contact.
    return '';
}

/**
 * Check if a module is supported.
 *
 * @param stdClass $gradeitem
 * @return bool
 */
function module_is_supported($gradeitem) {
    global $PAGE;

    // Course type is not supported.
    if ($gradeitem->itemtype == 'course') {
        return false;
    }

    // Manual feedback is supported.
    if ($gradeitem->itemtype == 'manual' && !$gradeitem->itemmodule) {
        return true;
    }

    // Invisible items are invisible unless you are editing.
    if (($gradeitem->hidden || !$gradeitem->visible) && !$PAGE->user_is_editing()) {
        return false;
    }

    // Todo: make an admin option.
    $supportedmodules = [
        'assign',
//        'lesson',
        'turnitintooltwo',
        'quiz',
//        'workshop',
    ];

    if (in_array($gradeitem->itemmodule, $supportedmodules)) {
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
    $validstatus = '';

    if ($gradeitem) {
        switch ($gradeitem->itemmodule) {
            case 'assign':
                $details = [
                    'table' => 'assign_submission',
                    'index' => 'assignment',
                    'user' => 'userid',
                    'date' => 'timemodified',
                    'status' => 'status',
                    ];
                $validstatus = 'submitted'; // Only get dates from submissions with a valid status.
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
            case 'turnitintooltwo':
                $details = [
                    'table' => 'turnitintooltwo_submissions',
                    'index' => 'turnitintooltwoid',
                    'user' => 'userid',
                    'date' => 'submission_modified',
                    'status' => '',
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
                    'status' => '',
                ];
                break;
            default:
                break;
        }
    }

    // Compute details.
    if (isset($details)) {
        if ($details['status'] != '' && $validstatus != '') {
            $submissionrecord = $DB->get_record($details['table'],
                [
                    $details['user'] => $userid,
                    $details['index'] => $gradeitem->iteminstance,
                    $details['status'] => $validstatus,
                ]
            );
        } else {
            $submissionrecord = $DB->get_record($details['table'],
                [
                    $details['user'] => $userid,
                    $details['index'] => $gradeitem->iteminstance,
                ]
            );
        }

        if ($submissionrecord) {
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
 * @param int $courseid
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
 * Get information about the module instance.
 *
 * Retired for now, but could still be useful...
 *
 * @param stdClass $gradeitem
 * @return false|mixed|stdClass
 * @throws dml_exception
 */
function get_feedback_module($gradeitem) {
    global $DB, $USER;

    // Handle cases of module types here where needed.
    $userkey = 'userid';
    switch ($gradeitem->itemmodule) {
        case 'assign':
            $moduletable = $gradeitem->itemmodule;
            $submissiontable = 'assign_submission';
            $submissionkey = 'assignment';
            $filter = ['field' => 'status', 'value' => 'submitted'];
            break;
        case 'lesson':
            $moduletable = $gradeitem->itemmodule;
            $replacements = ['deadline' => 'duedate'];
            $submissiontable = 'lesson_attempts';
            $submissionkey = 'lessonid';
            break;
        case 'quiz':
            $moduletable = $gradeitem->itemmodule;
            $replacements = ['timeclose' => 'duedate'];
            $submissiontable = 'quiz_attempts';
            $submissionkey = 'quiz';
            $filter = ['field' => 'state', 'value' => 'finished'];
            break;
        case 'scorm':
            $moduletable = $gradeitem->itemmodule;
            $replacements = ['timeclose' => 'duedate'];
            $submissiontable = 'scorm_attempt';
            $submissionkey = 'scormid';
            break;
        case 'turnitintooltwo':
            $moduletable = $gradeitem->itemmodule;
            // ToDo: Check source of due date.
            $submissiontable = 'turnitintooltwo_submissions';
            $submissionkey = 'turnitintooltwoid';
            $filter = ['field' => 'submission_type', 'value' => 1];
            break;
        case 'workshop':
            $moduletable = $gradeitem->itemmodule;
            $replacements = ['submissionend' => 'duedate'];
            $submissiontable = 'workshop_submissions';
            $submissionkey = 'workshopid';
            $userkey = 'authorid';
            break;
        case 'somethingelse':
            // Do something specific here.
            break;
        default:
            $moduletable = $gradeitem->itemmodule;
            break;
    }

    $feedbackmodule = $DB->get_record($moduletable, ['id' => $gradeitem->iteminstance]);

    // Compute replacement values.
    if (isset($replacements)) {
        foreach ($replacements as $from => $to) {
            $feedbackmodule->$to = $feedbackmodule->$from;
        }
        unset($replacement);
    }

    // The admin bits.
    if (is_course_editor($gradeitem->courseid, $USER->id)) {
        // Get the submissions when available.
        if (isset($submissiontable)) {
            // Get the count of submissions for the specified course module.
            $sql = "select
            count(distinct $userkey) as submissions
            from {" . $submissiontable . "}
            where $submissionkey = :instance
            ";
            if (isset($filter)) {
                $sql .= 'and ' . $filter['field'] . ' = "' . $filter['value'] . '"';
            }
            $params = ['instance' => $gradeitem->iteminstance];
            $result = $DB->get_record_sql($sql, $params);

            $feedbackmodule->submissions = $result->submissions;
        }
    }
    return $feedbackmodule;
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
