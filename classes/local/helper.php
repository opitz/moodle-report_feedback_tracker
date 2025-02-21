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
     * @return string
     */
    public static function get_academic_year(int $courseid): string {
        $academicyear = '';

        $handler = course_handler::create();
        $data = $handler->get_instance_data($courseid, true);
        foreach ($data as $dta) {
            if ($dta->get_field()->get('shortname') === "course_year") {
                $academicyear = $dta->get_value() ?? $academicyear;
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
     */
    public static function get_feedback_badge(stdClass $gradeitem, int $feedbackduedate, int $submissiondate): array {

        // If a grade item has not (yet) been released do not show a badge.
        if (($gradeitem->hiddengrade === 1) || ($gradeitem->hiddengrade > time())) {
            return [];
        }

        // If there is a custom feedback released date it will take precedence over an individual feedback date.
        $feedbackdate = $gradeitem->gfdate ?: $gradeitem->feedbackdate;

        // There is a feedback date.
        if ($feedbackdate) {
            // If there is no due date or the due date has not yet passed, feedback was released in time.
            if (!$feedbackduedate || $feedbackduedate >= $feedbackdate) {
                return ['released' => 'released'];
            } else { // Otherwise the feedback is late.
                return ['late' => 'late'];
            }
        } else if ($submissiondate && $feedbackduedate && $feedbackduedate < time()) {
            // There is a submission date, no feedback and the feedback due date has passed, then feedback is overdue.
            return ['overdue' => 'overdue'];
        } else { // No submission or no feedback due date or still within feedback period - show nothing.
            return [];
        }
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

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');
        $duedate = $gradeitem->duedate;

        // Submission was in time.
        if ($submissiondate && $submissiondate <= $duedate) {
            return html_writer::span(get_string('submission:success', 'report_feedback_tracker'),
                "badge badge-success",
                [
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'bottom',
                    'title' => get_string('submission:success', 'report_feedback_tracker') . " " .
                        userdate($submissiondate, $dateformat),
                ]);
        }

        // Submission was late.
        if ($duedate && $submissiondate && $submissiondate > $duedate) {
            return html_writer::span(get_string('submission:late', 'report_feedback_tracker'),
                "badge badge-warning",
                [
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'bottom',
                    'title' => get_string('submission:success', 'report_feedback_tracker') . " " .
                        userdate($submissiondate, $dateformat),
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
        $assesstypes['cmid'] = [];
        $assesstypes['gradeitemid'] = [];

        $labels = self::get_assesstype_labels();

        foreach (assess_type::get_assess_type_records_by_courseid($courseid) as $record) {
            $record->label = isset($labels[$record->type]) ? $labels[$record->type] : '';

            if ($record->cmid) {
                $assesstypes['cmid'][$record->cmid] = $record;
            }

            if ($record->gradeitemid) {
                $assesstypes['gradeitemid'][$record->gradeitemid] = $record;
            }
        }

        $assesstypes['notfound'] = new stdClass();
        $assesstypes['notfound']->type = -1;
        $assesstypes['notfound']->locked = false;

        return $assesstypes;
    }

    /**
     * Get the assessment type labels
     *
     * @return array For example:
     * * [
     * *   0 => 'Formative - does not contribute to course mark',
     * *   ⋮
     * * ]
     */
    private static function get_assesstype_labels(): array {
        return [
            assess_type::ASSESS_TYPE_FORMATIVE => get_string('formativeoption', 'local_assess_type'),
            assess_type::ASSESS_TYPE_SUMMATIVE => get_string('summativeoption', 'local_assess_type'),
            assess_type::ASSESS_TYPE_DUMMY => get_string('dummyoption', 'local_assess_type'),
        ];
    }

    /**
     * Get parts of turnitintooltwo assessments in the specified course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_tttparts($courseid): array {
        global $DB;

        $sql = "SELECT
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
                FROM {turnitintooltwo_parts} tttp
                JOIN {grade_items} gi ON gi.itemmodule = 'turnitintooltwo' AND gi.iteminstance = tttp.turnitintooltwoid
                LEFT JOIN {report_feedback_tracker} rft ON rft.gradeitem = gi.id AND rft.partid = tttp.id
                WHERE gi.courseid = :courseid";
        $params = ['courseid' => $courseid];
        return $DB->get_records_sql($sql, $params);
    }

    /**
     *
     * @param int $cmid
     * @return array
     */
    public static function get_turnitin_parts($cmid) {
        global $DB;

        return $DB->get_records('turnitintooltwo_parts', ['turnitintooltwoid' => $cmid], '',
        'id, partname, dtdue');
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
     * Return if user has archetype teacher or editingteacher.
     *
     * @param stdClass|null $course
     * @return bool
     */
    public static function is_teacher(stdClass|null $course = null): bool {
        global $DB, $USER;
        // Get id's from role where archetype is teacher or editingteacher.
        $params = ['role1' => 'editingteacher', 'role2' => 'teacher'];
        $roles = $DB->get_fieldset_select('role', 'id', 'archetype IN (:role1, :role2)', $params);

        if ($course) {
            // Check if user has expected role in the given course.
            foreach ($roles as $role) {
                if (user_has_role_assignment($USER->id, (int) $role, $course->ctxid)) {
                    return true;
                }
            }
            return false;

        } else {
            // Check if user has editingteacher role on any courses.
            list($roles, $params) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED);
            $params['userid'] = $USER->id;
            $sql = "SELECT id
                FROM {role_assignments}
                WHERE userid = :userid
                AND roleid $roles";
            return $DB->record_exists_sql($sql, $params);
        }
    }

    /**
     * Return the ability of a user to edit a course.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
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
     * @param object $gradeitem
     * @param int $duedate
     * @return int
     */
    public static function get_feedbackduedate(object $gradeitem, int $duedate): int {
        // If there is a manually set feedback due date use it.
        if (isset($gradeitem->feedbackduedate)) {
            return $gradeitem->feedbackduedate;
        }

        // If there is no due date there is no feedback due date.
        if (!$duedate) {
            return 0;
        }

        // For assessments before academic year 2024-25 the feedback due date period was 1 calendar month.
        if ((int) self::get_academic_year($gradeitem->courseid) < 2024) {
            return strtotime('+1 month', $duedate);
        }

        // From academic year 2024-25 on the feedback due date period is 20 working days.
        return self::compute_feedbackduedate($duedate);
    }

    /**
     * Compute the feedbackperiod.
     *
     * @param int $duedate The submission due date from which the feedback period starts.
     * @return int the feedback due date in seconds since midnight 01.01.1970.
     */
    private static function compute_feedbackduedate(int $duedate): int {
        $feedbackdeadlinedays = get_config('report_feedback_tracker', 'feedbackdeadlinedays');
        $closuredays = self::get_closuredays();

        // Initialize the start date.
        $feedbackduedatetime = $duedate;
        $daysadded = 0;
        // Loop until the required number of working days.
        while ($daysadded < $feedbackdeadlinedays) {
            // Increment the date by one day.
            $feedbackduedatetime += DAYSECS;

            // Check if the date is a weekend.
            $weekday = date('N', $feedbackduedatetime);

            // Count the day if it's not a weekend day (6 or 7) and not a closure date.
            if ($weekday < 6 && !in_array(date('Y-m-d', $feedbackduedatetime), $closuredays)) {
                $daysadded++;
            }
        }
        return $feedbackduedatetime;
    }

    /**
     * Get the bank holidays and university closure days.
     *
     * @return array
     */
    private static function get_closuredays() {

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
    private static function get_year_closuredays(
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
     */
    public static function get_course_url(int $courseid) {
        return new moodle_url('/course/view.php', ['id' => $courseid]);
    }

    /**
     * Get the assessment types.
     *
     * @param int|null $selection
     * @return array|object[]
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
     * Get the assessment type options.
     *
     * @return object[]
     */
    public static function get_assesstype_options() {
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
     * Try to get the assessment type and append it where found.
     *
     * @param stdClass $item
     * @param stdClass $assesstype
     * @return void
     */
    public static function add_assesstype(stdClass $item, stdClass $assesstype): void {
        $item->assesstype = $assesstype->type;
        $item->locked = $assesstype->locked;
        $item->selectedassesstypelabel = isset($assesstype->label) ? $assesstype->label : "";

        $item->formative = (int) $item->assesstype === assess_type::ASSESS_TYPE_FORMATIVE;
        $item->summative = (int) $item->assesstype === assess_type::ASSESS_TYPE_SUMMATIVE;
        $item->dummy = (int) $item->assesstype === assess_type::ASSESS_TYPE_DUMMY;
    }

    /**
     * Get the assessment type for a given grade item.
     *
     * @param int $gradeitemid
     * @param int $cmid
     * @param array $assesstypes
     * @return stdClass
     */
    public static function get_assesstype(int $gradeitemid, int $cmid, array $assesstypes): stdClass {
        if (isset($cmid) && isset($assesstypes['cmid'][$cmid])) {
            return $assesstypes['cmid'][$cmid];
        }

        if (isset($assesstypes['gradeitemid'][$gradeitemid])) {
            return $assesstypes['gradeitemid'][$gradeitemid];
        }

        return $assesstypes['notfound'];
    }

    /**
     * Get the part name.
     *
     * @param int $partid
     * @return string
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
     * Add additional data for the grade item where available.
     *
     * @param stdClass $item
     * @return void
     */
    public static function add_additional_data(stdClass $item): void {
        global $DB;

        $params['gradeitem'] = $item->gradeitemid;
        $params['partid'] = $item->partid ?: null;

        $item->notset = !$item->formative && !$item->summative && !$item->dummy;

        $item->hiddenfromreport = $item->dummy; // Dummy assessments are always hidden from the student report.

        // If the item has been saved show a confirmation.
        if (data_submitted() && confirm_sesskey()) {
            $itemid = required_param('itemid', PARAM_INT);
            $partid = required_param('partid', PARAM_INT);

            if (($itemid === (int) $item->gradeitemid) &&
                    ($partid === (int) $item->partid)) {
                $item->updated = true;
            }
        }

        // There should be only one record - make sure nevertheless...
        if ($record = $DB->get_record('report_feedback_tracker', $params, '*', IGNORE_MULTIPLE)) {
            $item->method = $record->method;
            $item->contact = $record->responsibility;
            $item->generalfeedback = $record->generalfeedback;

            if ($record->feedbackduedate) {
                $item->customfeedbackduedate = date('Y-m-d', $record->feedbackduedate);
                $item->feedbackduedateraw = $record->feedbackduedate;
                $item->feedbackduedate = userdate($record->feedbackduedate,
                    get_string('strftimedatemonthabbr', 'langconfig'));

                // Get a custom feedback due date reason entry for the grade item where available.
                $item->feedbackduedatereason = self::get_reason($item->gradeitemid, $item->partid, $item->feedbackduedate);
            }

            // Check if there is additional data to show.
            if ($item->generalfeedback || $item->method || $item->contact) {
                $item->additionaldata = true;
            }

            if ($record->gfdate) {
                $item->customfeedbackreleaseddate = date('Y-m-d', $record->gfdate);
            }

            $item->hiddenfromreport = (isset($item->hiddenfromreport) && $item->hiddenfromreport) || $record->hidden;
        }
    }

    /**
     * Return the current reason for a custom feedback due date or false.
     *
     * @param int $gradeitemid
     * @param int|null $partid
     * @param string $feedbackduedate
     * @return string
     */
    public static function get_reason(int $gradeitemid, int|null $partid, string $feedbackduedate): string {
        global $DB;

        // SQL query to fetch the record with the highest ID.
        // This will contain the latest reason for a given feedback due date if any.
        if (isset($partid)) {
            $sql = "SELECT dd.reason
                FROM {report_feedback_tracker_duedates} dd
                INNER JOIN (
                    SELECT MAX(id) AS maxid
                    FROM {report_feedback_tracker_duedates}
                    WHERE gradeitem = :gradeitem
                      AND partid = :partid
                      AND feedbackduedate = :feedbackduedate
                ) sub ON dd.id = sub.maxid";
            $params = [
                'gradeitem' => $gradeitemid,
                'partid' => $partid,
                'feedbackduedate' => strtotime($feedbackduedate),
            ];
        } else {
            $sql = "SELECT dd.reason
                FROM {report_feedback_tracker_duedates} dd
                INNER JOIN (
                    SELECT MAX(id) AS maxid
                    FROM {report_feedback_tracker_duedates}
                    WHERE gradeitem = :gradeitem
                      AND partid IS NULL
                      AND feedbackduedate = :feedbackduedate
                ) sub ON dd.id = sub.maxid";
            $params = [
                'gradeitem' => $gradeitemid,
                'feedbackduedate' => strtotime($feedbackduedate),
            ];
        }
        $record = $DB->get_record_sql($sql, $params);

        return isset($record->reason) ? $record->reason : '';
    }

    /**
     * Add data for each Turnitin part separately.
     *
     * @param stdClass $data The data to render.
     * @param grade_item $gradeitem
     * @param stdClass $item The assessment row item for the report.
     * @param array $assesstypes Array of valid assessment types
     * @param int|null $assesstypefilter Optional type filter
     * @return void
     */
    public static function add_ttt_data(
        stdClass $data,
        grade_item $gradeitem,
        stdClass $item,
        array $assesstypes,
        ?int $assesstypefilter = null
    ): void {
        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');
        $tttparts = self::get_turnitin_parts($gradeitem->iteminstance);

        foreach ($tttparts as $tttpart) {
            // Each part of a turnitintooltwo assessment starts as a clone of the module
            // and adds data related to each part.

            $tttitem = clone $item;
            $assesstype = self::get_assesstype($tttitem->gradeitemid, $tttitem->cmid, $assesstypes);
            if (isset($assesstypefilter) && ((int) $assesstype->type !== $assesstypefilter)) {
                continue;
            }

            $tttitem->name = $gradeitem->itemname . " - " . $tttpart->partname;
            $tttitem->partid = $tttpart->id;

            // Get the due date for each part.
            $duedate = $tttpart->dtdue;
            // The feedback due date timestamp is needed for sorting.
            if (!$duedate) {
                $tttitem->feedbackduedateraw = 9999999999;
                $tttitem->feedbackduedate = false;
                $tttitem->duedate = false;
            } else {
                $tttitem->feedbackduedateraw = self::get_feedbackduedate($gradeitem, $duedate);
                $tttitem->feedbackduedate = userdate($tttitem->feedbackduedateraw, $dateformat);
                $tttitem->duedate = userdate($duedate, $dateformat);
            }

            self::add_assesstype($tttitem, $assesstype);
            self::add_additional_data($tttitem);

            $data->items[] = $tttitem;
        }

    }

    /**
     * Get the academic years from an array of courses.
     *
     * @param array $courses
     * @return array
     */
    public static function get_academic_years_from_courses(array $courses): array {
        $academicyears = [];
        foreach ($courses as $course) {
            if ($academicyear = self::get_academic_year($course->id)) {
                $y1y2 = $academicyear . '-' . ((int)substr($academicyear, -2) + 1);
                if (!isset($academicyears[$y1y2])) {
                    $obj = new stdClass();
                    $obj->key = $academicyear;
                    $obj->value = $y1y2;
                    $academicyears[$y1y2] = $obj;
                }
            }
        }
        // Return the years descending.
        krsort($academicyears);
        return array_values($academicyears);
    }

    /**
     * Get the academic year to show.
     *
     * @param array $academicyears
     * @return int|string
     */
    public static function get_year_to_show(array $academicyears) {
        if ($academicyears) {
            // Return the last academic year the user has been enrolled into a course.
            return $academicyears[0]->key;
        } else {
            // The user has not been enrolled into any course yet so return the current academic year.
            return self::get_current_academic_year();
        }
    }

    /**
     * Get the current academic year.
     *
     * @return string
     */
    public static function get_current_academic_year(): string {
        $currentyear = date('Y');
        $currentmonth = date('m');
        return $currentmonth >= 8 ? $currentyear : $currentyear - 1; // Academic Year begins 1st of August.
    }

    /**
     * Get course module ID for the grade item where it exists.
     *
     * @param int $gradeitemid
     * @return int
     */
    public static function get_cmid(int $gradeitemid): int {
        global $DB;

        $sql = "SELECT cm.id AS cmid
                FROM {grade_items} gi
                    JOIN {modules} mo ON gi.itemmodule = mo.name
                    JOIN {course_modules} cm ON cm.module = mo.id AND gi.iteminstance = cm.instance
                WHERE gi.id = :gradeitemid";
        $params = ['gradeitemid' => $gradeitemid];

        return (int) $DB->get_field_sql($sql, $params);
    }

}
