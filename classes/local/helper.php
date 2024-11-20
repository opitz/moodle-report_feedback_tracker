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
use assign;
use cm_info;
use coding_exception;
use context_course;
use context_module;
use core_course\customfield\course_handler;
use dml_exception;
use grade_item;
use html_writer;
use lang_string;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * This file contains helper functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Return all academic years from the custom field.
     * @return array
     * @throws dml_exception
     */
    public static function get_academic_years() {
        global $DB;

        $academicyears = [];
        // Get the field definition for the custom field 'course_year'.
        if ($field = $DB->get_record('customfield_field', ['shortname' => 'course_year'])) {
            // Use the field ID to get all records from customfield_data for this field and store the academic year array.
            if ($records = $DB->get_records('customfield_data', ['fieldid' => $field->id], 'charvalue')) {
                foreach ($records as $record) {
                    $academicyears[$record->charvalue] = $record->charvalue;
                }
            }
        }

        return $academicyears;
    }

    /**
     * Get course academic year from custom course fields.
     *
     * @param int $courseid
     * @return string|null
     */
    public static function get_academic_year(int $courseid): ?string {
        $academicyear = null;
        $handler = course_handler::create();
        $data = $handler->get_instance_data($courseid, true);
        foreach ($data as $dta) {
            if ($dta->get_field()->get('shortname') === "course_year") {
                $academicyear = !empty($dta->get_value()) ? $dta->get_value() : null;
            }
        }
        return $academicyear;
    }

    /**
     * Get a feedback badge.
     *
     * @param stdClass $gradeitem
     * @param int $feedbackduedate
     * @param int $submissiondate
     * @return array
     * @throws coding_exception
     */
    public static function get_feedback_badge(stdClass $gradeitem, int $feedbackduedate, int $submissiondate): array {
        // A general feedback date  will take precedence if present.
        $feedbackdate = $gradeitem->gfdate ? $gradeitem->gfdate : $gradeitem->feedbackdate;

        // If there is no feedback and
        // there is no feedback due date or there is no submission show no badge.
        if (!$feedbackdate && (!$feedbackduedate || !$submissiondate)) {
            return [];
        }
        // If there is feedback but no feedback due date or
        // the feedback was given within the due date show a success badge.
        if ((!$feedbackduedate && $feedbackdate) ||
                ($feedbackduedate && $feedbackdate && ($feedbackdate <= $feedbackduedate))) {
            return ['released' => 'released'];
        }

        // Feedback was given after the feedback due date.
        if ($feedbackduedate && ($feedbackdate > $feedbackduedate)) {
            return ['late' => 'late'];
        }

        // NO feedback was given, and it is beyond the feedback due date.
        if ($feedbackduedate && !$feedbackdate && ($feedbackduedate < time())) {
            return ['overdue' => 'overdue'];
        }

        return [];
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
        if ((!$feedbackduedate && isset($gradeitem->finalgrade)) || (isset($gradeitem->gfdate) && ($gradeitem->gfdate > 0))) {
            return get_string('feedback:released', 'report_feedback_tracker');
        }

        // Feedback was given in time.
        if (isset($gradeitem->finalgrade) && ($gradeitem->feedbackdate <= $feedbackduedate)) {
            return get_string('feedback:released', 'report_feedback_tracker');
        }

        // Feedback was given after the feedback due date.
        if (isset($gradeitem->finalgrade) && ($gradeitem->feedbackdate > $feedbackduedate)) {
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
     * Return a link to the module item where applicable.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    public static function get_item_link(stdClass $gradeitem): string {
        $url = self::get_item_url($gradeitem);
        $linktext = self::get_item_name($gradeitem);
        return html_writer::link($url, $linktext);
    }

    /**
     * Return the name of the grade item combined with a part name where applicable.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    public static function get_item_name(stdClass $gradeitem): string {
        return (isset($gradeitem->partname) && $gradeitem->partname) ?
            "$gradeitem->itemname - $gradeitem->partname" : $gradeitem->itemname;
    }

    /**
     * Return a URL to the module item where applicable or to the gradebook otherwise.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    public static function get_item_url(stdClass $gradeitem): string {
        global $CFG, $USER;

        if (!isset($gradeitem->cmid)) {
            return "$CFG->wwwroot/grade/report/user/index.php?id=$gradeitem->courseid&userid=$USER->id";
        }
        return "$CFG->wwwroot/mod/$gradeitem->itemmodule/view.php?id=$gradeitem->cmid";
    }

    /**
     * Return an icon for a grade item module type where available.
     *
     * @param stdClass $gradeitem
     * @return mixed|string
     */
    public static function get_module_type_icon(stdClass $gradeitem): mixed {

        // If there is no itemmodule it is manual feedback.
        if (!$gradeitem->itemmodule) {
            return '<i class="icon fa-regular fa-hand-spock"></i>';
        }

        $path = $gradeitem->modinfo->get_icon_url()->out(false);

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
     * Count all submissions of a course module.
     *
     * @param cm_info $cm
     * @return array
     */
    public static function count_submissions(cm_info $cm): int {
        global $DB;

        if ($cm) {
            $context = context_module::instance($cm->id);
            switch ($cm->modname) {
                case 'assign':
                    // Get the group submission status.
                    $assignment = new assign($context, $cm, $cm->course);
                    return $assignment->count_submissions();
                case 'lesson':
                    $params = ['lessonid' => $cm->instance, 'correct' => 1];
                    return count($DB->get_records('lesson_attempts', $params));
                case 'quiz':
                    $params = ['quiz' => $cm->instance, 'state' => 'finished'];
                    return count($DB->get_records('quiz_attempts', $params));
                case 'turnitintooltwo':
                    $params = ['turnitintooltwoid' => $cm->instance];
                    return count($DB->get_records('turnitintooltwo_submissions', $params));
                case 'scorm':
                    return 0;
                case 'workshop':
                    $params = ['workshopid' => $cm->instance];
                    return count($DB->get_records('workshop_submissions', $params));
                default:
                    return 0;
            }
        }
        return [];
    }

    /**
     * Count the missing grades for a given grade item.
     *
     * @param grade_item $gradeitem
     * @param cm_info $cm
     * @param int $submissions the number of submissions for this course module.
     * @return int
     * @throws dml_exception
     */
    public static function count_missing_grades(grade_item $gradeitem, cm_info $cm, int $submissions): int {
        global $DB;

        // Assignments provide a way to count grades.
        if ($cm->modname === 'assign') {
            // Get the group submission status.
            $context = context_module::instance($cm->id);
            $assignment = new assign($context, $cm, $cm->course);
            return $assignment->count_submissions_need_grading();
        }

        // For modules other than assignments fetch the grades from the grade-grades table.
        $sql = "select distinct gg.userid
                from {grade_grades} gg
                where gg.itemid = :gradeitemid and gg.finalgrade > -1";

        // Execute the query.
        $grades = $DB->get_records_sql($sql, ['gradeitemid' => $gradeitem->id]);

        return $submissions - count($grades);
    }

    /**
     * Get a submission status icon.
     *
     * @param stdClass $gradeitem
     * @param int $submissiondate
     * @param int $warningperiod
     * @return string
     */
    public static function get_submission_status(stdClass $gradeitem, int $submissiondate, int $warningperiod): string {
        // If no submission type is available for an assignment there can be no submission
        // so do not show any submission badge.
        if (($gradeitem->itemmodule == 'assign') && !$gradeitem->submissiontypes) {
            return '';
        }

        $dateformat = get_config('report_feedback_tracker', 'dateformat');
        $duedate = $gradeitem->duedate;

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
     * Return an array of assessment types for a given course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_assessment_types(int $courseid): array {
        // Get all summative assessment type records from the assess type plugin.
        return assess_type::get_assess_type_records_by_courseid($courseid);
    }

    /**
     * Get parts of turnitintooltwo assessments in the specified course.
     *
     * @param int $courseid
     * @return array
     * @throws dml_exception
     */
    public static function get_tttparts($courseid): array {
        global $DB;

        $sql = "
    select
    tttp.*,
    gi.id AS gradeitemid,
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
    rft.gradeitem = gi.id and rft.partid = tttp.id
     WHERE gi.courseid = $courseid
    ";

        return $DB->get_records_sql($sql);
    }

    /**
     *
     * @param stdClass $gradeitem
     * @return array
     * @throws dml_exception
     */
    public static function get_turnitin_parts($gradeitem) {
        global $DB;

        $sql = "SELECT * FROM {turnitintooltwo_parts} WHERE turnitintooltwoid = :iteminstance";
        // Execute the query.
        $tttparts = $DB->get_records_sql($sql, ['iteminstance' => $gradeitem->iteminstance]);

        return $tttparts;

    }

    /**
     * Get Turnitin part records for course indexed by grade item ID.
     *
     * @param int $courseid
     * @return array Array of arrays.  The outer array is indexed by each grade
     * item ID, the inner array contains each part for that grade item, for
     * example, for grade item ID = 35 containing a single part:
     *   Array
     *   (
     *       [35] => Array
     *       (
     *           [0] => stdClass Object
     *           (
     *               [id] => 1
     *               [turnitintooltwoid] => 1
     *               [partid] => 1
     *               ⋮
     *           )
     *       )
     *   )
     */
    public static function get_turnitin_records(int $courseid): array {
        $tttparts = [];
        foreach (self::get_tttparts($courseid) as $tttpart) {
            $tttparts[$tttpart->gradeitemid][] = $tttpart;
        }
        return $tttparts;
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
        return has_capability('moodle/course:update', $coursecontext, $userid);
    }

    /**
     * Check if a module is supported for the admin report.
     *
     * @param string $itemmodule the item module (e.g. 'assign', 'quiz' etc.)
     * @return bool
     */
    public static function is_supported_module(string $itemmodule): bool {
        // Maybe chaching this?
        return get_config('report_feedback_tracker', 'support' . $itemmodule);
    }

    /**
     * Check if a module is supported for the student report.
     *
     * @param stdClass $gradeitem
     * @return bool
     */
    public static function module_is_supported(stdClass $gradeitem): bool {
        // Note: once the data collection for students will be refactored there will be only
        // one common method to check if a module is supported.
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

        return in_array($gradeitem->itemmodule, $modulelist) && self::is_supported_module($gradeitem->itemmodule);
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

        // If there is a submission due date calculate the feedback due date.
        if ($gradeitem->duedate) {
            $academicyear = (int) self::get_academic_year($gradeitem->courseid);

            // For assessments before academic year 2024-25 the feedback due date period was 1 calendar month.
            // From academic year 2024-25 on the feedback due date period is 20 working days.
            if ($academicyear < 2024) {
                return strtotime('+1 month', $gradeitem->duedate);
            }

            // Compute the due date.
            return self::compute_feedbackduedate($gradeitem->duedate);
        }

        // If there is no due date there is no feedback due date.
        return 0;
    }

    /**
     * Calculate the feedback due date based on the submission due date.
     *
     * @param int $courseid
     * @param int $duedate the submission due date
     * @return int
     * @throws dml_exception
     */
    public static function calculate_feedback_duedate(int $courseid, int $duedate): int {
        $academicyear = (int) self::get_academic_year($courseid);

        // If there is no due date there is no feedback due date.
        if (!$duedate) {
            return 0;
        }

        // For assessments before academic year 2024-25 the feedback due date period was 1 calendar month.
        // From academic year 2024-25 on the feedback due date period is 20 working days.
        if ($academicyear < 2024) {
            return strtotime('+1 month', $duedate);
        }
        return self::compute_feedbackduedate($duedate);
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
        $closuredays = self::get_closuredays();

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

        $cache = \cache::make('report_feedback_tracker', 'publicholidays');
        $closuredays = $cache->get('england_and_wales');
        $timestamp = $cache->get('timestamp');

        $academicyears = self::get_academic_years();

        if (!$closuredays || !$timestamp || ($timestamp < time() - DAYSECS)) {
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

            $cache->set('england_and_wales', $closuredays);
            $cache->set('timestamp', time());
        }

        // Now add the university closure days when not already covered.
        foreach ($academicyears as $year) {
            // We only need closure dates from 2024-25 on so skipping prior dates.
            if ((int) $year < 2024) {
                continue;
            }
            // Get the start and end dates for xmas and easter closures from the config.
            $xstart = get_config('report_feedback_tracker',
                "closure_xmas_start_{$year}");
            $xend = get_config('report_feedback_tracker',
                "closure_xmas_end_{$year}");
            $estart = get_config('report_feedback_tracker',
                "closure_easter_start_{$year}");
            $eend = get_config('report_feedback_tracker',
                "closure_easter_end_{$year}");

            // Add the closure days for the year.
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
        while ($date <= $enddate) {
            if (!in_array($date, $closuredays)) {
                $closuredays[] = $date;
            }
            $date = date('Y-m-d', strtotime($date . ' +1 day'));
        }

        // Then do the Easter closure.
        $date = date('Y-m-d', ($estart == '' ? 0 : strtotime($estart)));
        $enddate = date('Y-m-d', ($eend == '' ? 0 : strtotime($eend)));
        while ($date <= $enddate) {
            if (!in_array($date, $closuredays)) {
                $closuredays[] = $date;
            }
            $date = date('Y-m-d', strtotime($date . ' +1 day'));
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

    /**
     * Get the assessment types.
     *
     * @param int|null $selection
     * @return array|object[]
     * @throws coding_exception
     */
    public static function get_assess_types($selection) {

        $options = self::get_assesstype_options();

        // Prepare the options with selection logic.
        return array_map(function($option) use ($selection) {
            // Check if the current option value matches the selected value.
            $option->isselected = ($option->value === $selection);
            return $option;
        }, $options);
    }

    /**
     * Get the label of the selected assessment type.
     *
     * @param int $selection
     * @return string
     * @throws coding_exception
     */
    public static function get_selected_assess_type_label(int $selection): string {

        $options = self::get_assesstype_options();

        foreach ($options as $option) {
            if ($option->value === $selection) {
                return $option->label;
            }
        }
        return '';
    }

    /**
     * Get the assessment type options.
     *
     * @return object[]
     * @throws coding_exception
     */
    private static function get_assesstype_options() {
        return [
            (object)['value' => assess_type::ASSESS_TYPE_FORMATIVE,
                'label' => get_string('formativeoption', 'local_assess_type')],
            (object)['value' => assess_type::ASSESS_TYPE_SUMMATIVE,
                'label' => get_string('summativeoption', 'local_assess_type')],
            (object)['value' => assess_type::ASSESS_TYPE_DUMMY,
                'label' => get_string('dummyoption', 'local_assess_type')],
        ];
    }

    /**
     * Get the assessment type label of a given value.
     *
     * @param int|null $value
     * @return string
     * @throws coding_exception
     */
    public static function get_assesstype_label(int|null $value): string {
        $notfoundlabel = get_string('assesstype:notset', 'report_feedback_tracker');

        if ($value === null) {
            return $notfoundlabel;
        }

        $options = self::get_assesstype_options();
        foreach ($options as $option) {
            if ($option->value === $value) {
                return $option->label;
            }
        }

        return $notfoundlabel;
    }

    /**
     * Try to get the assessment type of the grade item and append it where found.
     *
     * @param stdClass $gradeitem
     * @param array $assessmenttypes
     * @return void
     */
    public static function append_assessment_type_to_gradeitem(stdClass &$gradeitem, array $assessmenttypes): void {
        foreach ($assessmenttypes as $assessmenttype) {
            // A course module with a part ID (e.g. turnitintooltwo).
            // Currently the part ID is not used for checking different parts until it is supported by local_assess_type.
            if (isset($gradeitem->cmid) && isset($gradeitem->partid) &&
                ($assessmenttype->cmid === $gradeitem->cmid)
            ) {
                $gradeitem->assessmenttype = $assessmenttype->type;
                $gradeitem->locked = $assessmenttype->locked;
                break;
            } else if ( // A course module w/o a part.
                isset($gradeitem->cmid) && !isset($gradeitem->partid) &&
                ($assessmenttype->cmid === $gradeitem->cmid)
            ) {
                $gradeitem->assessmenttype = $assessmenttype->type;
                $gradeitem->locked = $assessmenttype->locked;
                break;
            } else if ( // A grade item w/o a course module.
                !isset($gradeitem->cmid) && isset($gradeitem->itemid) &&
                ($assessmenttype->gradeitemid === $gradeitem->itemid)
            ) {
                $gradeitem->assessmenttype = $assessmenttype->type;
                $gradeitem->locked = $assessmenttype->locked;
                break;
            }
        }
    }

    /**
     * Get the assessment type and lock status for a course module and its optional part.
     *
     * @param stdClass $data
     * @param array $assessmenttypes
     * @return array
     */
    public static function get_assessment_type(stdClass $data, array $assessmenttypes): array {
        foreach ($assessmenttypes as $assessmenttype) {
            // A course module with a part ID (e.g. turnitintooltwo).
            // Currently, the part ID is not used for checking different parts until it is supported by local_assess_type.
            if (
                isset($data->cmid) && isset($data->partid) &&
                ($assessmenttype->cmid === $data->cmid)
            ) {
                return ['type' => $assessmenttype->type, 'locked' => $assessmenttype->locked];
            } else if ( // A course module w/o a part.
                isset($data->cmid) && !isset($data->partid) &&
                ($assessmenttype->cmid === $data->cmid)
            ) {
                return ['type' => $assessmenttype->type, 'locked' => $assessmenttype->locked];
            } else if ( // A grade item w/o a course module.
                !isset($data->cmid) && isset($data->itemid) &&
                ($assessmenttype->gradeitemid === $data->itemid)
            ) {
                return ['type' => $assessmenttype->type, 'locked' => $assessmenttype->locked];
            }
        }
        return ['type' => -1, 'locked' => false];
    }

    /**
     * Get the part name.
     *
     * @param int $partid
     * @return string
     * @throws dml_exception
     */
    public static function get_partname($partid) {
        global $DB;

        if ($record = $DB->get_record('turnitintooltwo_parts', ['id' => $partid])) {
            return $record->partname;
        }
        return '';
    }

    /**
     * Get the enabled submission types for a given course module
     *
     * @param int $cmid The ID of the course module
     * @return int
     */
    public static function get_assign_submission_plugins(int $cmid): int {
        global $DB;

        // Load the course module and assignment instance.
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        $assign = new assign($context, $cm, $cm->course);

        // Fetch the list of submission plugins and check their settings.
        $submissionplugins = $assign->get_submission_plugins();

        $enabledsubmissiontypes = [];

        foreach ($submissionplugins as $plugin) {
            // Check if the submission plugin is enabled and visible for this assignment.

            if ($plugin->is_enabled() && $plugin->is_visible()
                && $plugin->allow_submissions()) {
                $enabledsubmissiontypes[] = get_class($plugin);
            }
        }

        return count($enabledsubmissiontypes);
    }

    /**
     * Show the general feedback and the gf URL to students.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    public static function get_generalfeedback($gradeitem): string {

        $o = '';
        if ($gradeitem->generalfeedback) {
            $o .= html_writer::start_span('generalfeedback');
            $o .= html_writer::span($gradeitem->generalfeedback, 'generalfeedbacktext',
                ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);
            $link = "<a href='$gradeitem->gfurl'>$gradeitem->gfurl</a>";
            $o .= html_writer::span($link, 'gfurl',
                ['id' => 'gfurl_' . $gradeitem->itemid]);

            $o .= html_writer::end_span();
        }
        return $o;
    }

    /**
     * Get the students of a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return array
     */
    public static function get_course_students(int $courseid, int $userid = 0): array {
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context);
        $students = [];
        foreach ($users as $user) {
            // Check if the user has no managerial or supervising capabilities (e.g. is a student).
            if (!has_capability('gradereport/grader:view', $context, $user) &&
                !has_capability('moodle/course:manageactivities', $context, $user) &&
                !has_capability('enrol/category:synchronised', $context, $user) &&
                !has_capability('moodle/course:view', $context, $user)
            ) {
                if ((int)$user->id === $userid) {
                    $user->selected  = true;
                }
                $students[] = $user;
            } else { // If a user has a managerial or supervising role check if there is (also) a student role.
                $roles = get_user_roles($context, $user->id, true);
                foreach ($roles as $role) {
                    if (strstr($role->shortname, 'student')) {
                        if ($user->id === $userid) {
                            $user->selected  = true;
                        }
                        $students[] = $user;
                        break;
                    }
                }
            }
        }
        return $students;
    }

    /**
     * Get the due date of a course module.
     *
     * @param cm_info $cm
     * @return int
     * @throws dml_exception
     */
    public static function get_duedate(cm_info $cm): int {
        global $DB;

        switch ($cm->modname) {
            case 'assign':
                $record = $DB->get_record('assign', ['id' => $cm->instance], 'duedate');
                $duedate = $record->duedate;
                break;
            case 'lesson':
                $record = $DB->get_record('lesson', ['id' => $cm->instance], 'deadline');
                $duedate = $record->deadline;
                break;
            case 'quiz':
                $record = $DB->get_record('quiz', ['id' => $cm->instance], 'timeclose');
                $duedate = $record->timeclose;
                break;
            case 'workshop':
                $record = $DB->get_record('workshop', ['id' => $cm->instance], 'submissionend');
                $duedate = $record->submissionend;
                break;
            default:
                $duedate = 0;
        }

        return $duedate;
    }

    /**
     * Get the number of students that have a submission due date override for a given course module.
     *
     * @param cm_info $module
     * @return int
     * @throws dml_exception
     */
    public static function get_overrides(cm_info $module): int {
        global $DB;

        switch ($module->modname) {
            case 'assign':
                $idfield = 'assignid';
                break;
            case 'lesson':
                $idfield = 'lessonid';
                break;
            case 'quiz':
                $idfield = 'quiz';
                break;
            default:
                return 0; // Return no overrides.
        }

        $overrides = [];
        // Get user overrides.
        $overridetable = $module->modname . "_overrides";
        $useroverrides = $DB->get_records_sql("
            SELECT *
            FROM {" . $overridetable . "}
            WHERE $idfield = :moduleid AND userid IS NOT NULL", ['moduleid' => $module->instance]);

        foreach ($useroverrides as $useroverride) {
            $overrides[$useroverride->userid] = $useroverride->userid;
        }

        // Get group overrides and users in those groups.
        $groupoverrides = $DB->get_records_sql("
            SELECT gm.*
            FROM {" . $overridetable . "} ao
            JOIN {groups_members} gm ON ao.groupid = gm.groupid
            WHERE ao.$idfield = :moduleid AND ao.groupid IS NOT NULL", ['moduleid' => $module->instance]);

        foreach ($groupoverrides as $groupoverride) {
            $overrides[$groupoverride->userid] = $groupoverride->userid;
        }

        // Count unique users.
        return count(array_unique($overrides));
    }

    /**
     * Provide a URL of the override settings of a given course module where available.
     *
     * @param cm_info $module
     * @return string
     */
    public static function get_overrides_url(cm_info $module): string {
        $supportedmodules = ['assign', 'lesson', 'quiz'];
        if (in_array($module->modname, $supportedmodules)) {
            return "/mod/$module->modname/overrides.php?cmid=$module->id";
        }
        return "#";
    }

    /**
     * Count the grades for a given grade item.
     *
     * @param grade_item $gradeitem
     * @param cm_info $cm
     * @return int
     * @throws dml_exception
     */
    public static function count_grades(grade_item $gradeitem, cm_info $cm): int {
        global $DB;

        $sql = "select distinct gg.userid
                from {grade_grades} gg
                where gg.itemid = :gradeitemid and gg.finalgrade > -1";

        // Execute the query.
        $grades = $DB->get_records_sql($sql, ['gradeitemid' => $gradeitem->id]);

        // Assignments may have group submissions.
        if ($cm->modname === 'assign') {
            // Get the group submission status.
            $context = context_module::instance($cm->id);
            $assignment = new assign($context, $cm, $cm->course);

            // If group submissions are enabled count only group grades.
            if ($assignment->get_instance()->teamsubmission) {
                return self::count_group_grades($gradeitem->courseid, $grades);
            }
        }
        return count($grades);
    }

    /**
     * Extract the group grades from individual grades and count them.
     *
     * @param int $courseid
     * @param array $grades the individual grades.
     * @return int
     */
    public static function count_group_grades(int $courseid, array $grades): int {
        $groupgrades = [];
        foreach ($grades as $grade) {
            // For the specified course get the groups the graded user belongs to.
            $usergroups = groups_get_user_groups($courseid, $grade->userid);
            foreach ($usergroups as $usergroup) {
                // Add only one grade per group.
                $groupindex = isset($usergroup[0]) ? $usergroup[0] : 0; // Group ID 0 is the default group.
                $groupgrades[$groupindex] = true;
            }
        }
        return count($groupgrades);
    }
}

