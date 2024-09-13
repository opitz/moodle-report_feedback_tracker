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

namespace report_feedback_tracker\local;
use coding_exception;
use context_course;
use dml_exception;
use grade_item;
use html_writer;
use lang_string;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;

/**
 * This file contains helper functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Get a random academic year for test purposes only..
     *
     * @param int $courseid
     */
    public static function get_academic_year_dummy(int $courseid): ?string {
        // Return a random academic year from the array.
        $dummyacademicyears = ['2021-22', '2022-23', '2023-24', '2024-25'];
        return $dummyacademicyears[array_rand($dummyacademicyears)];
    }

    /**
     * Get course academic year from custom course fields.
     *
     * @param int $courseid
     */
    public static function get_academic_year(int $courseid): ?string {
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
     * Get the feedbacks and submissions.
     *
     * @param stdClass $gradeitem
     * @return string
     * @throws dml_exception
     */
    public static function get_feedbacks(stdClass $gradeitem): string {
        return $gradeitem->cmid ? html_writer::div("$gradeitem->feedbacks of $gradeitem->submissions") : '';
    }

    /**
     * Get a feedback badge.
     *
     * @param stdClass $gradeitem
     * @param int $feedbackduedate
     * @param int $submissiondate
     * @return string
     * @throws coding_exception
     */
    public static function get_feedback_badge(stdClass $gradeitem, int $feedbackduedate, int $submissiondate): string {

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
    public static function get_feedback_method(stdClass $gradeitem): string|null {
        global $OUTPUT, $PAGE;

        if ($PAGE->user_is_editing()) {
            // We need to differentiate parts of ttt assessments - so include the partname in the identifying blob.
            $idblob = implode(',', [$gradeitem->itemid, $gradeitem->partname]);
            $edititem = new \core\output\inplace_editable(
                'report_feedback_tracker',
                'method',
                $idblob,
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
    public static function get_feedback_responsibility(stdClass $gradeitem): string {
        global $OUTPUT, $PAGE;

        if ($PAGE->user_is_editing()) {
            $idblob = implode(',', [$gradeitem->itemid, $gradeitem->partname]);
            $edititem = new \core\output\inplace_editable(
                'report_feedback_tracker',
                'responsibility',
                $idblob,
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
     *
     * @param stdClass $gradeitem
     * @param int $feedbackduedate
     * @param int $submissiondate
     * @return lang_string|string
     * @throws coding_exception
     */
    public static function get_feedback_status(stdClass $gradeitem, int $feedbackduedate, int $submissiondate): lang_string|string {

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
    public static function get_hidden_state(stdClass $gradeitem): string {
        global $PAGE;

        if ($PAGE->user_is_editing()) {
            if ($gradeitem->hidden) {
                return "<input
                data-action='report_feedback_tracker/hiding_checkbox'
                type='checkbox'
                class='form-check-input hiding_checkbox'
                cmid='$gradeitem->itemid'
                partname='$gradeitem->partname'
                checked='checked'
            >";
            } else {
                return "<input
                data-action='report_feedback_tracker/hiding_checkbox'
                type='checkbox'
                class='form-check-input hiding_checkbox'
                cmid='$gradeitem->itemid'
                partname='$gradeitem->partname'
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
     * Return a link to the module item where applicable.
     *
     * @param stdClass $gradeitem
     * @return mixed|string
     */
    public static function get_item_link(stdClass $gradeitem): mixed {
        global $CFG, $USER;

        if (!isset($gradeitem->cmid)) {
            $url = "$CFG->wwwroot/grade/report/user/index.php?id=$gradeitem->courseid&userid=$USER->id";
        } else {
            $url = "$CFG->wwwroot/mod/$gradeitem->itemmodule/view.php?id=$gradeitem->cmid";
        }
        $linktext = $gradeitem->partname ? "$gradeitem->itemname - $gradeitem->partname" : $gradeitem->itemname;
        return html_writer::link($url, $linktext);
    }

    /**
     * Return an icon for a module type where available.
     *
     * @param stdClass $gradeitem
     * @return mixed|string
     */
    public static function get_item_type(stdClass $gradeitem): mixed {

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
     * @return \lang_string|string
     * @throws coding_exception
     */
    public static function get_item_module(stdClass $gradeitem): \lang_string|string {
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
    public static function get_submissiondate(int $userid, stdClass $gradeitem): int {
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
                    $validstatus = 'finished'; // Only get dates from submissions with a valid status.
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
                $submissionrecords = $DB->get_records($details['table'],
                    [
                        $details['user'] => $userid,
                        $details['index'] => $gradeitem->iteminstance,
                        $details['status'] => $validstatus,
                    ]
                );
            } else {
                $submissionrecords = $DB->get_records($details['table'],
                    [
                        $details['user'] => $userid,
                        $details['index'] => $gradeitem->iteminstance,
                    ]
                );
            }

            if ($submissionrecords) {
                // Get the latest valid submission only.
                usort($submissionrecords, function($a, $b) use ($details) {
                    return $b->{$details['date']} <=> $a->{$details['date']}; // Sorts in descending order.
                });
                $submissionrecord = $submissionrecords[0];
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
    public static function get_submission_status(int $submissiondate, int $duedate, int $warningperiod): string {
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

        // NO submission but approaching due date within warning period. Unused for now.
        if (!$submissiondate && time() <= $duedate && time() >= $duedate - $warningperiod && false) {
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
    public static function get_summative_ids(int $courseid): array {
        global $CFG;

        require_once($CFG->dirroot . '/grade/lib.php');
        $assesstypegradeitems = [];

        // Get all summative assessment type records from the assess type plugin.
        $assesstyperecords = file_exists($CFG->dirroot.'/local/assess_type/version.php') ?
            assess_type::get_assess_type_records_by_courseid($courseid, assess_type::ASSESS_TYPE_SUMMATIVE) :
        [];

        // No assess type records found.
        if (empty($assesstyperecords)) {
            return [];
        }

        foreach ($assesstyperecords as $assesstype) {
            // Grade item id found. Return the assess type record and process the next record.
            if ($assesstype->gradeitemid) {
                $assesstypegradeitems[$assesstype->gradeitemid] = $assesstype->locked;
                continue;
            }

            // Course module id found.
            // Find the grade items of this course module and return their assess type records.
            if ($assesstype->cmid) {
                $cm = get_coursemodule_from_id('', $assesstype->cmid, $assesstype->courseid);
                if (empty($cm)) {
                    continue;
                }
                $gradeitems = grade_item::fetch_all(
                  ['itemtype' => 'mod', 'iteminstance' => $cm->instance, 'itemmodule' => $cm->modname]
                );
                if (empty($gradeitems)) {
                    continue;
                }
                foreach ($gradeitems as $gradeitem) {
                    $assesstypegradeitems[$gradeitem->id] = $assesstype->locked;
                }
            }
        }

        return $assesstypegradeitems;
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
    protected static function get_ttt_submission_date(stdClass $tttpart, int $userid): mixed {
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
    public static function get_tttparts($gradeitem): array {
        global $DB;

        $sql = "
    select
    tttp.*,
    rft.summative,
    rft.hidden,
    rft.feedbackduedate,
    rft.method,
    rft.responsibility,
    rft.generalfeedback,
    rft.gfurl,
    rft.gfdate
    from {turnitintooltwo_parts} tttp
    join {grade_items} gi on gi.itemmodule = 'turnitintooltwo' and gi.iteminstance = tttp.turnitintooltwoid
    left join {report_feedback_tracker} rft on
        rft.gradeitem = gi.id and rft.partname collate utf8mb4_unicode_ci = tttp.partname collate utf8mb4_unicode_ci
    where tttp.turnitintooltwoid = $gradeitem->iteminstance
    ";

        return $DB->get_records_sql($sql);
    }

    /**
     * Get a due date extension where available.
     *
     * @param stdClass $gradeitem
     * @param int $userid
     * @return false|mixed
     * @throws dml_exception
     */
    public static function get_duedate_extension(stdClass $gradeitem, int $userid): mixed {
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
     * Return the ability of a user to edit a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     * @throws coding_exception
     */
    public static function is_course_editor(int $courseid, int $userid): bool {
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
    public static function module_is_supported(stdClass $gradeitem): bool {
        global $PAGE;

        // Course type is not supported.
        if ($gradeitem->itemtype == 'course') {
            return false;
        }

        // Manual feedback is supported if checked in the settings.
        if ($gradeitem->itemtype == 'manual' && !$gradeitem->itemmodule &&
            get_config('report_feedback_tracker', 'supportmanual')) {
            // Do not show hidden manual items unless the user is editing.
            if ($gradeitem->hidden && !$PAGE->user_is_editing()) {
                return false;
            }
            return true;
        }

        // Invisible or hidden items are invisible unless you are editing.
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
     * @param int $date the feedback due date in seconds since midnight 01.01.1970.
     * @return string
     * @throws coding_exception
     */
    public static function render_feedbackduedate(stdClass $gradeitem, int $date = 0): string {
        global $PAGE;

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
                'partname' => $gradeitem->partname,
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
    protected static function get_feedback_module(stdClass $gradeitem): mixed {
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
        if (self::is_course_editor($gradeitem->courseid, $USER->id)) {
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
     * Get the feedback due date for a grade item.
     *
     * @param stdClass $gradeitem
     * @return int
     * @throws dml_exception
     */
    public static function get_feedbackduedate(stdClass $gradeitem): int {
        // If there is a manually set feedback due date use it.
        if ($gradeitem->feedbackduedate) {
            return $gradeitem->feedbackduedate;
        }

        // If there is a due date compute the feedback due date.
        if ($gradeitem->duedate) {
            // Compute the due date.
            return self::compute_feedbackduedate($gradeitem->duedate);
        }

        // If there is no due date there is no feedback due date.
        return 0;
    }

    /**
     * Compute the feedbackperiod.
     *
     * @param int $duedate The submission due date from which the feedback period starts.
     * @return int the feedback due date in seconds since midnight 01.01.1970.
     * @throws dml_exception
     */
    protected static function compute_feedbackduedate(int $duedate): int {
        $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
        static $closuredays = null;

        if ($closuredays === null) {
            // Get the closure days including bank holidays for England and
            // Wales.  These are obtained from a URL so only do this once.
            $closuredays = self::get_closuredays();
        }

        // Initialize the start date.
        $currentdate = date('Y-m-d', $duedate);
        $daysadded = 0;

        // Loop until the required number of working days.
        while ($daysadded < $feedbackdeadlinedays) {
            // Increment the current date by one day.
            $currentdate = date('Y-m-d', strtotime($currentdate . ' +1 day'));

            // Check if the current date is a weekend.
            $weekday = date('N', strtotime($currentdate)); // 6 = Saturday, 7 = Sunday

            // Skip the day if it's a weekend (6 or 7) or a closure date.
            if ($weekday < 6 && !in_array($currentdate, $closuredays)) {
                $daysadded++;
            }
        }

        return strtotime($currentdate);

    }

    /**
     * Get the bank holidays and university closure days.
     *
     * @return array
     * @throws dml_exception
     */
    protected static function get_closuredays() {

        $closuredays = [];

        // Get the official Bank holidays for England and Wales.
        // Fetch data from the API and decode it into an array.
        $jsondata = file_get_contents('https://www.gov.uk/bank-holidays.json');
        $bankholidays = json_decode($jsondata, true);
        // Accessing bank holidays for England and Wales.
        $englandwalesholidays = $bankholidays['england-and-wales']['events'];

        // We only need the dates.
        foreach ($englandwalesholidays as $holiday) {
            $closuredays[] = $holiday['date'];
        }

        // Now add the university closure days when not already covered.
        // Loop over the current year and 4 years back.
        $xmasstartsetting = 'closure_xmas_start';
        $xmasendsetting = 'closure_xmas_end';
        $easterstartsetting = 'closure_easter_start';
        $easterendsetting = 'closure_easter_end';
        for ($i = 0; $i <= 4; $i++) {
            // Get the start and end dates for xmas and easter closures from the config.
            $xstartname = $i == 0 ? $xmasstartsetting : $xmasstartsetting . '_' . $i;
            $xendname = $i == 0 ? $xmasendsetting : $xmasendsetting . '_' . $i;
            $estartname = $i == 0 ? $easterstartsetting : $easterstartsetting . '_' . $i;
            $eendname = $i == 0 ? $easterendsetting : $easterendsetting . '_' . $i;

            $xstart = get_config('report_feedback_tracker', $xstartname);
            $xend = get_config('report_feedback_tracker', $xendname);
            $estart = get_config('report_feedback_tracker', $estartname);
            $eend = get_config('report_feedback_tracker', $eendname);

            self::get_year_closuredays($closuredays, $xstart, $xend, $estart, $eend);
        }

        return $closuredays;
    }

    /**
     * Add the closure days for given xmas and easter periods of a year.
     *
     * @param array $closuredays
     * @param string $xstart
     * @param string $xend
     * @param string $estart
     * @param string $eend
     * @return void
     */
    protected static function get_year_closuredays(
        array &$closuredays, string $xstart, string $xend, string $estart, string $eend): void {
        // Do the Xmas closure 1st.
        $date = date('Y-m-d', ($xstart == '' ? 0 : strtotime($xstart)));
        $enddate = date('Y-m-d', ($xend == '' ? 0 : strtotime($xend)));
        while ($date < $enddate) {
            $date = date('Y-m-d', strtotime($date . ' +1 day'));

            if (!in_array($date, $closuredays)) {
                $closuredays[] = $date;
            }
        }

        // Then do the Easter closure.
        $date = date('Y-m-d', ($estart == '' ? 0 : strtotime($estart)));
        $enddate = date('Y-m-d', ($eend == '' ? 0 : strtotime($eend)));
        while ($date < $enddate) {
            $date = date('Y-m-d', strtotime($date . ' +1 day'));

            if (!in_array($date, $closuredays)) {
                $closuredays[] = $date;
            }
        }
    }

    /**
     * Return the Moodle URL of the course.
     *
     * @param int $courseid
     * @return moodle_url
     * @throws \moodle_exception
     */
    public static function get_course_url(int $courseid) {
        return new moodle_url('/course/view.php', ['id' => $courseid]);
    }
}
