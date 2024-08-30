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
 * This file contains functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once(__DIR__.'/lib.php');

/**
 * Get the gradings for all users of a course and amend the data with the findings.
 *
 * @param stdClass $course
 * @param stdClass $data
 * @return void
 * @throws dml_exception
 */
function get_admin_course_gradings($course, &$data) {
    global $CFG, $DB;

    $sql = "
    select
        ROW_NUMBER() OVER (ORDER BY gi.id) AS uniqueid,
        gi.courseid,
        gi.id as itemid,
        gi.itemname,
        gi.itemtype,
        gi.itemmodule,
        cm.id as cmid,
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
        (select count(distinct gg.userid) from {grade_grades} gg where gg.itemid = gi.id and gg.finalgrade > -1) as feedbacks,
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
";
    // Check if Turnitintooltwo is installed.
    if (file_exists($CFG->dirroot.'/mod/turnitintooltwo/version.php')) {
        $sql .= "
            WHEN gi.itemmodule = 'turnitintooltwo' THEN
                (select count(distinct ts.userid) from {turnitintooltwo_submissions} ts
                                                  where ts.turnitintooltwoid = gi.iteminstance and ts.submission_type = 1)
";
    }
    $sql .= "
            WHEN gi.itemmodule = 'workshop' THEN
                (select count(distinct ws.authorid) from {workshop_submissions} ws where ws.workshopid = gi.iteminstance)
            ELSE 0
        END as submissions,
        rft.summative,
        rft.hidden,
        rft.feedbackduedate,
        rft.method,
        rft.responsibility,
        rft.generalfeedback,
        rft.gfurl,
        rft.gfdate
    from {grade_items} gi
        left JOIN {modules} m on m.name = gi.itemmodule
        left JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.course = gi.courseid AND m.id = cm.module
        left JOIN {report_feedback_tracker} rft on rft.gradeitem = gi.id
    where gi.courseid = :courseid
";
    $params['courseid'] = $course->id;
    $gradeitems = $DB->get_records_sql($sql, $params);

    $summativeids = get_summative_ids($course->id);

    foreach ($gradeitems as $gradeitem) {
        // Check if the gradeitem module is supported.
        if (!module_is_supported($gradeitem)) {
            continue;
        }

        // All good - now get and store the feedback record.
        // TurnitinToolTwo special treatment as one grading item may have several parts.
        if ($gradeitem->itemmodule == 'turnitintooltwo') {
            get_admin_turnitin_records($course, $gradeitem, $summativeids, $data);
        } else {
            $record = get_admin_feedback_record($course, $gradeitem, $summativeids);
            $data->records[] = $record;
        }
    }

    // Get the filter options where available.
    get_admin_filter_options($data);

    // Sort the records by feedback due date.
    if (is_array($data->records)) {
        usort($data->records, function($a, $b) {
            return strcmp($a->feedbackduedateraw, $b->feedbackduedateraw);
        });
    }
}

/**
 * Get the admin feedback record for a grade item.
 *
 * @param stdClass $course
 * @param stdClass $gradeitem
 * @param array $summativeids
 * @return stdClass
 * @throws dml_exception
 */
function get_admin_feedback_record ($course, $gradeitem, $summativeids) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $dateformat = get_config('report_feedback_tracker', 'dateformat');
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.

    $record = new stdClass();
    $record->course = $course->fullname;
    $record->courseid = $course->id;
    $record->coursename = $course->fullname;
    $record->academicyear = get_academic_year($gradeitem->courseid);
    $record->assessment = get_item_link($gradeitem);
    $record->type = get_item_type($gradeitem);
    $record->module = get_item_module($gradeitem);
    $record->duedate = $gradeitem->duedate == 0 ? get_string('datenotset', 'report_feedback_tracker') : date($dateformat, $gradeitem->duedate);
    $record->duedateraw = $gradeitem->duedate == 0 ? 9999999999 : $gradeitem->duedate;
    $record->feedbackduedate = render_feedbackduedate($gradeitem, $feedbackperiod);
    $record->feedbackduedateraw = $gradeitem->feedbackduedate ? $gradeitem->feedbackduedate :
        ($gradeitem->duedate ? $gradeitem->duedate + $feedbackperiod : 9999999999);
    $record->feedbacks = get_feedbacks($gradeitem);
    $record->method = get_feedback_method($gradeitem);
    $record->responsibility = get_feedback_responsibility($gradeitem);
    $record->generalfeedback = get_admin_generalfeedback($gradeitem);
    $record->cohortfeedback = get_admin_cohortfeedback($gradeitem);
    $record->gfurl = $gradeitem->gfurl;
    $record->summative = get_admin_summative($gradeitem, $summativeids);
    $record->summativetext = $gradeitem->summative ? get_string('summative', 'report_feedback_tracker') : "";
    $record->hidden = get_hidden_state($gradeitem);

    return $record;
}

/**
 * Get a random academic year for test purposes only..
 *
 * @param int $courseid
 */
function get_academic_year(int $courseid): ?string {
    // Return a random academic year from the array.
    $dummyacademicyears = ['2021-22', '2022-23', '2023-24', '2024-25'];
    return $dummyacademicyears[array_rand($dummyacademicyears)];
}

/**
 * Get course academic year from custom course fields.
 *
 * @param int $courseid
 */
function get_academic_year0(int $courseid): ?string {
    $academicyear = null;
    $handler = \core_course\customfield\course_handler::create();
    $data = $handler->get_instance_data($courseid, true);
    foreach ($data as $dta) {
        if ($dta->get_field()->get('shortname') === "course_year") {
            $academicyear = !empty($dta->get_value()) ? $dta->get_value() : null;
        }
    }
    if ($academicyear) {
        $suffix = (int)substr($academicyear, -2) + 1;
        $academicyear .= "-$suffix";

    }
    return $academicyear;
}

/**
 * Show/edit the general feedback for a grade item.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_admin_generalfeedback($gradeitem) {
    global $PAGE;

    $o = html_writer::start_div('generalfeedback align-items-center');
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
                    'data-gfdate' => $gradeitem->gfdate,
                ]);
    } else {
        $o .= html_writer::span($gradeitem->generalfeedback, 'generalfeedbacktext',
            ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);
    }

    // Show the URL.
    $link = "<a href='$gradeitem->gfurl'>$gradeitem->gfurl</a>";
    $o .= html_writer::div($link, 'gfurl',
        ['id' => 'gfurl_' . $gradeitem->itemid]);

    $o .= html_writer::end_div();
    return $o;
}

/**
 * Get the options for filtering the admin table.
 *
 * @param stdClass $data
 * @return void
 */
function get_admin_filter_options(&$data) {
    // The filter options.
    $data->academicyearoptions = [];
    $data->courseoptions = [];
    $data->typeoptions = [];
    $data->summativeoptions = [];
    $data->feedbackoptions = [];
    $data->methodoptions = [];

    foreach ($data->records as $record) {

        // Academic year options.
        if ($record->academicyear) {
            $option = new stdClass();
            $option->key = $record->academicyear;
            $option->value = $record->academicyear;
            if (!in_array($option, $data->academicyearoptions)) {
                $data->academicyearoptions[] = $option;
            }
        }

        // Course options.
        if ($record->courseid) {
            $option = new stdClass();
            $option->key = $record->courseid;
            $option->value = $record->coursename;
            $option->academicyear = $record->academicyear;
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

    // Sort the academic year descending.
    if (is_array($data->academicyearoptions)) {
        usort($data->academicyearoptions, function($a, $b) {
            return $b->value <=> $a->value; // For descending order
        });
    }
}

/**
 * Show / edit the summative state of a grading item.
 *
 * @param stdClass $gradeitem
 * @param array $summativeids an array with summative item ids from sitsgradepush
 * @return string
 */
function get_admin_summative($gradeitem, $summativeids) {
    global $PAGE;

    // Check if an item is declared summative by SITS.
    if (in_array($gradeitem->itemid, $summativeids)) {
        $gradeitem->summative = 2;
    }

    // If not set by SITS one may still declare summative manually.
    if ($PAGE->user_is_editing() && $gradeitem->summative < 2) {
        if ($gradeitem->summative) {
            return "<input
                data-action='report_feedback_tracker/summative_checkbox'
                type='checkbox'
                class='form-check-input summative_checkbox'
                cmid='$gradeitem->itemid'
                checked='checked'
            >";
        } else {
            return "<input
                data-action='report_feedback_tracker/summative_checkbox'
                type='checkbox'
                class='form-check-input summative_checkbox'
                cmid='$gradeitem->itemid'
            >";
        }
    } else {
        return $gradeitem->summative ? "<i class='fa fa-check'></i>" : '';
    }
}

/**
 * Get the parts of a turnitintooltwo grading item and list them as separate items.
 *
 * @param stdClass $course
 * @param stdClass $gradeitem
 * @param array $summativeids
 * @param stdClass $data
 * @return void
 * @throws dml_exception
 */
function get_admin_turnitin_records($course, $gradeitem, $summativeids, &$data) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.
    $dateformat = get_config('report_feedback_tracker', 'dateformat');

    // Get the parts.
    $tttparts = get_tttparts($gradeitem);

    // Make each part a record and store it in the data.
    foreach ($tttparts as $tttpart) {
        $duedate = $tttpart->dtdue; // Each part may have its own due date.
        // If there is a manual feedback due date use it, otherwise calculate it from the submission due date.
        $feedbackduedate = $gradeitem->feedbackduedate ?? ($duedate ? $duedate + $feedbackperiod : 0);

        $record = new stdClass();
        $record->course = $course->fullname;
        $record->courseid = $course->id;
        $record->coursename = $course->fullname;
        $record->academicyear = get_academic_year($gradeitem->courseid);
        $record->assessment = get_item_link($gradeitem, $tttpart->partname);
        $record->type = get_item_type($gradeitem);
        $record->module = get_item_module($gradeitem);
        $record->duedate = $duedate == 0 ? get_string('datenotset', 'report_feedback_tracker') : date($dateformat, $duedate);
        $record->duedateraw = $duedate == 0 ? 9999999999 : $duedate;
        $record->feedbackduedate = render_feedbackduedate($gradeitem, $feedbackperiod);
        $record->feedbackduedateraw = $gradeitem->feedbackduedate ? $gradeitem->feedbackduedate :
            ($gradeitem->duedate ? $gradeitem->duedate + $feedbackperiod : 9999999999);
        $record->feedbacks = get_feedbacks($gradeitem);
        $record->method = get_feedback_method($gradeitem);
        $record->responsibility = get_feedback_responsibility($gradeitem);
        $record->generalfeedback = get_admin_generalfeedback($gradeitem);
        $record->cohortfeedback = get_admin_cohortfeedback($gradeitem);
        $record->gfurl = $gradeitem->gfurl;
        $record->summative = get_admin_summative($gradeitem, $summativeids);
        $record->summativetext = $gradeitem->summative ? get_string('summative', 'report_feedback_tracker') : "";
        $record->hidden = get_hidden_state($gradeitem);
        $data->records[] = $record;
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

    return html_writer::link("$CFG->wwwroot/course/view.php?id=$course->id", $course->fullname);
}

/**
 * Get the feedbacks and submissions.
 *
 * @param stdClass $gradeitem
 * @return string
 * @throws dml_exception
 */
function get_feedbacks($gradeitem) {
    return $gradeitem->cmid ? html_writer::div("$gradeitem->feedbacks of $gradeitem->submissions") : '';
}

/**
 * Get a feedback badge.
 *
 * @param stdClass $gradeitem
 * @param int $feedbackduedate
 * @param int $feedbackextendperiod
 * @param int $submissiondate
 * @return string
 * @throws coding_exception
 */
function get_feedback_badge($gradeitem, $feedbackduedate, $feedbackextendperiod, $submissiondate) {

    // If there is no general feedback date and no submission there is no feedback.
    if (!isset($gradeitem->gfdate) && $submissiondate == 0) {
        return '';
    }

    $o = '';
    $contact = $gradeitem->responsibility;

    // Feedback is available even if there is no due date or when only cohort feedback is given.
    if ((!$feedbackduedate && isset($gradeitem->finalgrade)) || (isset($gradeitem->gfdate) && $gradeitem->gfdate > 0)) {
        $o .= html_writer::span(get_string('feedback:released', 'report_feedback_tracker'),
            "badge badge-success");
    } else if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate <= $feedbackduedate) {
        // Feedback was given in time.
        $o .= html_writer::span(get_string('feedback:released', 'report_feedback_tracker'),
            "badge badge-success");
    } else if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate > $feedbackduedate) {
        // Feedback was given after the feedback due date.
        $o .= html_writer::span(get_string('feedback:late', 'report_feedback_tracker'),
            "badge badge-warning");
    } else if (!isset($gradeitem->finalgrade) && $feedbackduedate < time()) {
        // NO feedback was given, and it is beyond the feedback due date.
        $o .= html_writer::span(get_string('feedback:overdue', 'report_feedback_tracker'),
            "badge badge-danger");
    }

    if ($contact && false) { // Do not show for now.
        $o .= html_writer::start_span('feedback_tracker_contact');
        $o .= html_writer::tag('small', get_string('contact', 'report_feedback_tracker') . ': ');
        $o .= html_writer::span($contact, 'feedback_tracker_contact_body small');
        $o .= html_writer::end_span();
    }
    return $o;
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
        return html_writer::div($OUTPUT->render($edititem), "d-flex align-items-center");
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
 * Get a feedback status.
 * @param stdClass $gradeitem
 * @param int $feedbackduedate
 * @param int $feedbackextendperiod
 * @param int $submissiondate
 * @return lang_string|string
 * @throws coding_exception
 */
function get_feedback_status($gradeitem, $feedbackduedate, $feedbackextendperiod, $submissiondate) {

    // If there is no general feedback date and no submission there is no feedback(?).
    if (!isset($gradeitem->gfdate) && $submissiondate == 0) {
        return '';
    }

    // Feedback is available even if there is no due date or when only cohort feedback is given.
    if ((!$feedbackduedate && isset($gradeitem->finalgrade)) || (isset($gradeitem->gfdate) && $gradeitem->gfdate > 0)) {
        return get_string('feedback:released', 'report_feedback_tracker');
    }

    // Feedback was given in time.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate <= $feedbackduedate) {
        return get_string('feedback:released', 'report_feedback_tracker');
    }

    // Feedback was given after the feedback due date.
    if (isset($gradeitem->finalgrade) && $gradeitem->feedbackdate > $feedbackduedate) {
        return get_string('feedback:late', 'report_feedback_tracker');
    }

    // NO feedback was given, and it is beyond the feedback due date.
    if (!isset($gradeitem->finalgrade) && $feedbackduedate < time()) {
        return get_string('feedback:overdue', 'report_feedback_tracker');
    }

    // The feedback is due within the due time - so do nothing and show a contact.
    return '';
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
                class='form-check-input hiding_checkbox'
                cmid='$gradeitem->itemid'
                checked='checked'
            >";
        } else {
            return "<input
                data-action='report_feedback_tracker/hiding_checkbox'
                type='checkbox'
                class='form-check-input hiding_checkbox'
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
 * Edit / show the cohort feedback status for course admins.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_admin_cohortfeedback($gradeitem) {
    global $PAGE;

    if ($PAGE->user_is_editing()) {
        if ($gradeitem->gfdate) {
            return "<input
                data-action='report_feedback_tracker/cohort_checkbox'
                type='checkbox'
                class='form-check-input cohort_checkbox'
                cmid='$gradeitem->itemid'
                checked='checked'
            >";
        } else {
            return "<input
                data-action='report_feedback_tracker/cohort_checkbox'
                type='checkbox'
                class='form-check-input cohort_checkbox'
                cmid='$gradeitem->itemid'
            >";
        }
    } else {
        if ($gradeitem->gfdate) {
            return "<i class='fa fa-check'></i>";
        } else {
            return '';
        }
    }
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

    if (!isset($gradeitem->cmid)) {
        $url = "$CFG->wwwroot/grade/report/user/index.php?id=$gradeitem->courseid&userid=$USER->id";
    } else {
        $url = "$CFG->wwwroot/mod/$gradeitem->itemmodule/view.php?id=$gradeitem->cmid";
    }
    $linktext = $partname ? "$gradeitem->itemname - $partname" : $gradeitem->itemname;
    return html_writer::link($url, $linktext);
}

/**
 * Return an icon for a module type where available.
 *
 * @param stdClass $gradeitem
 * @return mixed|string
 */
function get_item_type($gradeitem) {

    // If there is no itemmodule it is manual feedback.
    if (!$gradeitem->itemmodule) {
        return '<i class="icon fa-regular fa-hand-spock"></i>';
    }

    $modinfo = get_fast_modinfo($gradeitem->courseid)->get_cm($gradeitem->cmid);
    $path = $modinfo->get_icon_url()->out(false);

    switch ($gradeitem->itemmodule) {
        case 'assign':
            $title = get_string('pluginname', 'mod_assign');
            break;
        case 'lesson':
            $title = get_string('pluginname', 'mod_lesson');
            break;
        case 'quiz':
            $title = get_string('pluginname', 'mod_quiz');
            break;
        case 'turnitintooltwo':
            $title = get_string('pluginname', 'mod_turnitintooltwo');
            break;
        case 'scorm':
            $title = get_string('pluginname', 'mod_scorm');
            break;
        case 'workshop':
            $title = get_string('pluginname', 'mod_workshop');
            break;
        default:
              return $gradeitem->itemmodule;
    }

    return "<img class='icon mr-0' src='$path' alt='$gradeitem->itemmodule' title=$title>";
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
 * Get a submission status icon.
 *
 * @param int $submissiondate
 * @param int $duedate
 * @param int $warningperiod
 * @return string
 */
function get_submission_status($submissiondate, $duedate, $warningperiod) {
    $dateformat = get_config('report_feedback_tracker', 'dateformat');

    // Submission was in time.
    if ($submissiondate && $submissiondate <= $duedate) {
        return html_writer::span(get_string('submission:success', 'report_feedback_tracker'),
            "badge badge-success",
            [
                'data-toggle' => 'tooltip',
                'data-placement' => 'bottom',
                'title' => "Submitted " . date($dateformat, $submissiondate),
            ]);
    }

    // Submission was late.
    if ($duedate && $submissiondate && $submissiondate > $duedate) {
        return html_writer::span(get_string('submission:late', 'report_feedback_tracker'),
            "badge badge-warning",
            [
                'data-toggle' => 'tooltip',
                'data-placement' => 'bottom',
                'title' => "Submitted " . date($dateformat, $submissiondate),
            ]);
    }

    // NO submission but approaching due date within warning period.
    if (!$submissiondate && time() <= $duedate && time() >= $duedate - $warningperiod) {
        return html_writer::span(get_string('submission:warning', 'report_feedback_tracker'),
            "badge badge-warning");
    }

    // NO submission and the due date has passed.
    if ($duedate && !$submissiondate && time() > $duedate ) {
        return html_writer::span(get_string('submission:overdue', 'report_feedback_tracker'),
            "badge badge-danger");
    }

    // The submission is not due yet - so return nothing.
    return '';
}

/**
 * Return an array of IDs of summative assessments for a given course
 *
 * @param int $courseid
 * @return array
 */
function get_summative_ids($courseid) {
    global $CFG;

    $summativeids = [];
    // Check if SITSgradepush is installed.
    if (file_exists($CFG->dirroot.'/local/sitsgradepush/version.php')) {
        require_once($CFG->dirroot . '/local/sitsgradepush/classes/external/get_summative_grade_items.php');

        $instance = new local_sitsgradepush\external\get_summative_grade_items;
        $result = $instance::execute($courseid);
        $summativegradeitems = $result['gradeitems'];
        // Build an array of summative IDs.
        foreach ($summativegradeitems as $summativegradeitem) {
            $summativeids[] = $summativegradeitem->id;
        }
    }
    return $summativeids;
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
                (select duedate from {assign} where id = gi.iteminstance )
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
        cm.id as cmid,
        cm.visible,
        um.username as grader,
        gg.timemodified,
        rft.summative,
        rft.hidden,
        rft.feedbackduedate,
        rft.method,
        rft.responsibility,
        rft.generalfeedback,
        rft.gfurl,
        rft.gfdate
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

    $summativeids = get_summative_ids($course->id);

    $courseobject = new stdClass();
    $courseobject->courseid = $course->id;
    $courseobject->shortname = $course->shortname;
    $courseobject->fullname = $course->fullname;
    $courseobject->academicyear = get_academic_year($course->id);
    $courseobject->image = \core_course\external\course_summary_exporter::get_course_image($course);

    foreach ($gradeitems as $gradeitem) {
        // Check if the gradeitem module is supported.
        if (!module_is_supported($gradeitem)) {
            continue;
        }

        // If item is a module (e.g. not manual) check if a user is allowed to access it.
        if ($userid && $gradeitem->itemmodule) {
            if (!\core_availability\info_module::is_user_visible($gradeitem->cmid, $userid, false)) {
                continue;
            }
        }

        // All good - now get and store the feedback record.
        // Check for due date extensions.
        if ($extension = get_duedate_extension($gradeitem, $userid)) {
            $gradeitem->duedate = $extension;
        }
        // TurnitinToolTwo special treatment as one grading item may have several parts.
        if ($gradeitem->itemmodule == 'turnitintooltwo') {
            get_user_turnitin_records($course, $gradeitem, $userid, $summativeids, $data, $courseobject);
        } else {
            $record = get_user_feedback_record($course, $userid, $gradeitem, $summativeids);
            $data->records[] = $record;
            $courseobject->records[] = $record;
        }
    }

    // Sort the courseobject records by due date.
    if (is_array($courseobject->records)) {
        usort($courseobject->records, function($a, $b) {
            return strcmp($a->duedateraw, $b->duedateraw);
        });
    }

    $data->courses[] = $courseobject;

    // Get the options for academic years.
    get_user_academic_years($data);
}

function get_user_academic_years(&$data) {
    $data->academicyearoptions = [];

    foreach ($data->courses as $course) {
        $option = new stdClass();
        $option->key = $course->academicyear;
        $option->value = $course->academicyear;
        if (!in_array($option, $data->academicyearoptions) && $option->key !== null) {
            $data->academicyearoptions[] = $option;
        }
    }
    // Sort the academic year descending.
    if (is_array($data->academicyearoptions)) {
        $data->hasyears = $data->academicyearoptions ? true : false;
            usort($data->academicyearoptions, function($a, $b) {
            return $b->value <=> $a->value; // For descending order
        });
    }

}
/**
 * Get a due date extension where available.
 *
 * @param stdClass $gradeitem
 * @param int $userid
 * @return false|mixed
 * @throws dml_exception
 */
function get_duedate_extension($gradeitem, $userid) {
    global $DB;

    switch ($gradeitem->itemmodule) {
        case "assign":
            return $DB->get_field('assign_user_flags', 'extensionduedate',
                ['assignment' => $gradeitem->iteminstance, 'userid' => $userid]);
        case "quiz":
            // Quizzes may have group and/or user due date extensions. Return whatever is higher.
            $groupextension = 0;
            if ($usergroups = groups_get_user_groups($gradeitem->courseid, $userid)[0]) {
                foreach ($usergroups as $usergroupid) {
                    if ($gext = $DB->get_field('quiz_overrides', 'timeclose',
                        ['quiz' => $gradeitem->iteminstance, 'groupid' => $usergroupid])) {
                        $groupextension = $gext > $groupextension ? $gext : $groupextension;
                    }
                }
            }
            $userextension = $DB->get_field('quiz_overrides', 'timeclose',
                ['quiz' => $gradeitem->iteminstance, 'userid' => $userid]);

            return $groupextension > $userextension ? $groupextension : $userextension;
        case "turnitintooltwo":
            return false;
        default:
            return false;
    }
}

/**
 * Get the user feedback record for a grade item.
 *
 * @param stdClass $course
 * @param int $userid
 * @param stdClass $gradeitem
 * @param array $summativeids
 * @return stdClass
 * @throws dml_exception
 */
function get_user_feedback_record($course, $userid, $gradeitem, $summativeids) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.

    $warningdays = get_config('report_feedback_tracker', 'warningdays');
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackextenddays = get_config('report_feedback_tracker', 'feedbackextenddays');
    $warningperiod = $warningdays * $oneday; // Number of seconds in the warning period.
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.
    $feedbackextendperiod = $feedbackextenddays * $oneday; // Number of seconds in the feedback period.
    $dateformat = get_config('report_feedback_tracker', 'dateformat');

    // If there is a manual feedback due date use it, otherwise calculate it from the submission due date where set.
    $feedbackduedate = $gradeitem->feedbackduedate ? $gradeitem->feedbackduedate :
        ($gradeitem->duedate ? $gradeitem->duedate + $feedbackperiod : 0);
    // Get the submission date if any.
    $submissiondate = get_submissiondate($userid, $gradeitem);

    $record = new stdClass();
    $record->submissiondate = $submissiondate == 0 ? '--' : date($dateformat, $submissiondate);
    $record->submissionstatus = get_submission_status($submissiondate, $gradeitem->duedate, $warningperiod);
    $record->course = $course->fullname;
    $record->courseid = $course->id;
    $record->coursename = $course->fullname;
    $record->academicyear = get_academic_year($gradeitem->courseid);
    $record->assessment = get_item_link($gradeitem);
    $record->type = get_item_type($gradeitem);
    $record->module = get_item_module($gradeitem);
    $record->summative = get_user_summative($gradeitem, $summativeids);
    $record->duedate = $gradeitem->duedate == 0 ? get_string('datenotset', 'report_feedback_tracker') : date($dateformat, $gradeitem->duedate);
    $record->duedateraw = $gradeitem->duedate == 0 ? 9999999999 : $gradeitem->duedate;
    $record->feedbackduedate = $feedbackduedate == 0 ? get_string('datenotset', 'report_feedback_tracker') : date($dateformat, $feedbackduedate);
    $record->feedbackduedateraw = $feedbackduedate == 0 ? 9999999999 : $feedbackduedate;
    $record->grade = ($gradeitem->finalgrade ?
        (int)$gradeitem->finalgrade . '/' . (int)$gradeitem->grademax : false);
    $record->student = $gradeitem->student;
    $record->grader = $gradeitem->grader;
    $record->feedbackdate = $gradeitem->feedbackdate ? $gradeitem->feedbackdate : $gradeitem->gfdate;
    $record->feedbackstatus = get_feedback_status($gradeitem, $feedbackduedate, $feedbackextendperiod, $submissiondate);
    $record->feedbackbadge = get_feedback_badge($gradeitem, $feedbackduedate, $feedbackextendperiod, $submissiondate);
    $record->method = $gradeitem->method;
    $record->responsibility = html_writer::div($gradeitem->responsibility);
    $record->generalfeedback = $gradeitem->generalfeedback;
    $record->gfurl = $gradeitem->gfurl;
    $record->contact = $gradeitem->responsibility;

    $record->additionaldata = $record->generalfeedback || $record->method || $record->contact;

    return $record;
}

/**
 * Get the options for filtering the user table.
 *
 * @param stdClass $data
 * @return void
 */
function get_user_filter_options(&$data) {
    // The filter options.
    $data->academicyearoptions = [];
    $data->courseoptions = [];
    $data->typeoptions = [];
    $data->summativeoptions = [];
    $data->feedbackoptions = [];
    $data->methodoptions = [];

    foreach ($data->records as $record) {

        // Academic year options.
        if ($record->academicyear) {
            $option = new stdClass();
            $option->key = $record->academicyear;
            $option->value = $record->academicyear;
            if (!in_array($option, $data->academicyearoptions)) {
                $data->academicyearoptions[] = $option;
            }
        }

        // Course options.
        if ($record->courseid) {
            $option = new stdClass();
            $option->key = $record->courseid;
            $option->value = $record->coursename;
            $option->academicyear = $record->academicyear;
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

    // Sort the academic year descending.
    if (is_array($data->academicyearoptions)) {
        usort($data->academicyearoptions, function($a, $b) {
            return $b->value <=> $a->value; // For descending order
        });
    }
}

/**
 * Show the general feedback and the gf URL to students.
 *
 * @param stdClass $gradeitem
 * @return string
 */
function get_user_generalfeedback($gradeitem) {

    $o = '';
    if ($gradeitem->generalfeedback) {
        $o .= html_writer::start_div('generalfeedback');
        $o .= html_writer::div($gradeitem->generalfeedback, 'generalfeedbacktext',
            ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);
        $link = "<a href='$gradeitem->gfurl'>$gradeitem->gfurl</a>";
        $o .= html_writer::div($link, 'gfurl',
            ['id' => 'gfurl_' . $gradeitem->itemid]);

        $o .= html_writer::end_div();
    }
    return $o;
}

/**
 * Show a summative text for a summative grade item in students/users report.
 *
 * @param stdClass $gradeitem
 * @param array $summativeids
 * @return lang_string|string
 * @throws coding_exception
 */
function get_user_summative($gradeitem, $summativeids) {
    return $gradeitem->summative || in_array($gradeitem->itemid, $summativeids) ?
        get_string('summative', 'report_feedback_tracker') : "";
}

/**
 * Get the parts of a turnitintooltwo grading item and list them as separate items.
 *
 * @param stdClass $course
 * @param stdClass $gradeitem
 * @param int $userid
 * @param array $summativeids
 * @param stdClass $data
 * @param stdClass $courseobject
 * @return void
 * @throws dml_exception
 */
function get_user_turnitin_records($course, $gradeitem, $userid, $summativeids, &$data, &$courseobject) {

    $oneday = 24 * 60 * 60; // Number of seconds in a day.

    $warningdays = get_config('report_feedback_tracker', 'warningdays');
    $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
    $feedbackextenddays = get_config('report_feedback_tracker', 'feedbackextenddays');
    $warningperiod = $warningdays * $oneday; // Number of seconds in the warning period.
    $feedbackperiod = $feedbackdeadlinedays * $oneday; // Number of seconds in the feedback period.
    $feedbackextendperiod = $feedbackextenddays * $oneday; // Number of seconds in the feedback period.
    $dateformat = get_config('report_feedback_tracker', 'dateformat');

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
        $record->submissiondate = $submissiondate == 0 ? '--' : date($dateformat, $submissiondate);
        $record->submissionstatus = get_submission_status($submissiondate, $duedate, $warningperiod);
        $record->course = $course->fullname;
        $record->courseid = $course->id;
        $record->coursename = $course->fullname;
        $record->academicyear = get_academic_year($gradeitem->courseid);
        $record->assessment = get_item_link($gradeitem, $tttpart->partname);
        $record->type = get_item_type($gradeitem);
        $record->module = get_item_module($gradeitem);
        $record->summative = get_user_summative($gradeitem, $summativeids);
        $record->duedate = $duedate == 0 ? get_string('datenotset', 'report_feedback_tracker') : date($dateformat, $duedate);
        $record->duedateraw = $duedate == 0 ? 9999999999 : $duedate;
        $record->feedbackduedate = $feedbackduedate == 0 ? get_string('datenotset', 'report_feedback_tracker') : date($dateformat, $feedbackduedate);
        $record->feedbackduedateraw = $feedbackduedate == 0 ? 9999999999 : $feedbackduedate;
        $record->grade = ($gradeitem->finalgrade ?
            (int)$gradeitem->finalgrade . '/' . (int)$gradeitem->grademax : false);
        $record->student = $gradeitem->student;
        $record->grader = $gradeitem->grader;
        $record->feedbackstatus = get_feedback_status($gradeitem, $feedbackduedate, $feedbackextendperiod, $submissiondate);
        $record->feedbackbadge = get_feedback_badge($gradeitem, $feedbackduedate, $feedbackextendperiod, $submissiondate);
        $record->method = $gradeitem->method;
        $record->contact = $gradeitem->responsibility;

        $data->records[] = $record;
        $courseobject->records[] = $record;
    }
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

    // Manual feedback is supported if checked in the settings.
    if ($gradeitem->itemtype == 'manual' && !$gradeitem->itemmodule &&
        get_config('report_feedback_tracker', 'supportmanual')) {
        return true;
    }

    // Invisible items are invisible unless you are editing.
    if (($gradeitem->hidden || !$gradeitem->visible) && !$PAGE->user_is_editing()) {
        return false;
    }

    $modulelist = [
        'assign',
        'lesson',
        'turnitintooltwo',
        'quiz',
        'workshop',
    ];

    $supportedmodules = [];

    foreach ($modulelist as $module) {
        if (get_config('report_feedback_tracker', 'support' . $module)) {
            array_push($supportedmodules, $module);
        }
    }

    if (in_array($gradeitem->itemmodule, $supportedmodules)) {
        return true;
    }
    return false;
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

    $o = html_writer::start_div("d-flex align-items-center");
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
            'data-deadlinedays' => get_config('report_feedback_tracker', 'feedbackdeadlinedays'),
        ]);

        $o .= $inputfield;
    } else { // Just return the date.
        $dateformat = get_config('report_feedback_tracker', 'dateformat');
        $o .= $date ? date($dateformat, $date) : '--';
    }

    // Show a hint badge when date is set manually.
    if ($date) {
        $classes = 'fa fa-info-circle text-primary ml-1';
        $style = $gradeitem->feedbackduedate ? '' : 'display: none;';
        $title = get_string('feedbackduedate:custom', 'report_feedback_tracker');
        $o .= " <i class='$classes' title='$title' data-itemid='$gradeitem->itemid'
                data-action='report_feedback_tracker/customhint' style='$style'></i>";
    }
    $o .= html_writer::end_div();
    return $o;
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

