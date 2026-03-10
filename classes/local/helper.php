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
use core\exception\moodle_exception;
use core_course\customfield\course_handler;
use course_modinfo;
use curl;
use dml_exception;
use Exception;
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
     * Autumn academic term.
     */
    const TERM_AUTUMN = 1;

    /**
     * Spring academic term.
     */
    const TERM_SPRING = 2;

    /**
     * Summer academic term.
     */
    const TERM_SUMMER = 3;

    /**
     * Optinal parameter to show all assessments in site report
     */
    const ASSESS_TYPE_ALL = 2;

    /**
     * Start month of the academic year.
     */
    const AY_START_MONTH = 10;

    /**
     * @var array of assessment types.
     */
    public static array $assesstypes;

    /**
     * Return academic years 1 year back and 3 years into the future.
     *
     * @return array
     */
    public static function get_academic_years() {
        $currentyear = self::get_current_academic_year();
        $academicyears[] = $currentyear - 1;
        $academicyears[] = $currentyear;
        $academicyears[] = $currentyear + 1;
        $academicyears[] = $currentyear + 2;
        $academicyears[] = $currentyear + 3;

        return $academicyears;
    }

    /**
     * Get course academic year from custom course fields.
     *
     * @param int $courseid
     * @return int|null
     */
    public static function get_academic_year(int $courseid): ?int {
        $handler = course_handler::create();
        $data = $handler->get_instance_data($courseid, true);
        foreach ($data as $dta) {
            if ($dta->get_field()->get('shortname') === "course_year") {
                return $dta->get_value() ?: null;
            }
        }
        return null;
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
        $assesstypes['notfound']->label = '';

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
     *
     * @param int $cmid
     * @return array
     */
    public static function get_turnitin_parts($cmid) {
        global $DB;

        return $DB->get_records(
            'turnitintooltwo_parts',
            ['turnitintooltwoid' => $cmid],
            '',
            'id, partname, dtdue'
        );
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
            [$roles, $params] = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED);
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
        if (self::get_academic_year($gradeitem->courseid) < 2024) {
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
        $feedbackduedatetime = strtotime(date('Y-m-d', $duedate));
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

        // The feedback due date ends midnight of that day regardless of the submission due date time.
        return $feedbackduedatetime + DAYSECS - 1;
    }

    /**
     * Get the bank holidays and university closure days.
     *
     * @return array
     */
    public static function get_closuredays() {
        global $CFG;

        $cache = \cache::make('report_feedback_tracker', 'publicholidays');
        $closuredays = $cache->get('england_and_wales');
        $timestamp = $cache->get('timestamp');

        $academicyears = self::get_academic_years();

        if (!$closuredays || !$timestamp || ($timestamp < time() - DAYSECS)) {
            $closuredays = [];

            // Get the official Bank holidays for England and Wales.
            $curl = new curl();

            // When testing make sure to use preserved holiday data from 2019 to 2028 to match test definitions.
            if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
                $curl->mock_response(file_get_contents($CFG->dirroot . '/report/feedback_tracker/tests/bankholidays2019-28.json'));
            }
            // Fetch data from the official API.
            $jsondata = $curl->get('https://www.gov.uk/bank-holidays.json');

            // Decode into an array and use the dates for England and Wales.
            $bankholidays = json_decode($jsondata, true);
            if (empty($bankholidays['england-and-wales']['events'])) {
                throw new moodle_exception('error:unable_to_load_holidays', 'report_feedback_tracker');
            }
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
            $xstart = get_config(
                'report_feedback_tracker',
                "closure_xmas_start_{$year}"
            );
            $xend = get_config(
                'report_feedback_tracker',
                "closure_xmas_end_{$year}"
            );
            $estart = get_config(
                'report_feedback_tracker',
                "closure_easter_start_{$year}"
            );
            $eend = get_config(
                'report_feedback_tracker',
                "closure_easter_end_{$year}"
            );

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
        array &$closuredays,
        string $xstart,
        string $xend,
        string $estart,
        string $eend
    ): void {
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
     * @return stdClass
     */
    public static function get_assesstype(int $gradeitemid, int $cmid): stdClass {
        if (isset(self::$assesstypes['cmid'][$cmid])) {
            return self::$assesstypes['cmid'][$cmid];
        }

        if (isset(self::$assesstypes['gradeitemid'][$gradeitemid])) {
            return self::$assesstypes['gradeitemid'][$gradeitemid];
        }

        return self::$assesstypes['notfound'];
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
            if (
                !has_capability('gradereport/grader:view', $context, $user) &&
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

        // Assessment type.
        $assesstype = self::get_assesstype($item->gradeitemid, $item->cmid ?? 0);
        self::add_assesstype($item, $assesstype);

        $item->notset = !$item->formative && !$item->summative && !$item->dummy;

        $item->hiddenfromreport = $item->dummy; // Dummy assessments are always hidden from the student report.

        // If the item has been saved show a confirmation.
        if (data_submitted() && confirm_sesskey()) {
            $itemid = required_param('itemid', PARAM_INT);
            $partid = required_param('partid', PARAM_INT);

            if (
                ($itemid === (int) $item->gradeitemid) &&
                    ($partid === (int) $item->partid)
            ) {
                $item->updated = true;
            }
        }

        // There should be only one record - make sure nevertheless...
        if ($record = $DB->get_record('report_feedback_tracker', $params, '*', IGNORE_MULTIPLE)) {
            $item->method = s($record->method);
            $item->contact = s($record->responsibility);
            $item->generalfeedback = s($record->generalfeedback);

            if ($record->feedbackduedate) {
                $item->customfeedbackduedate = date('Y-m-d', $record->feedbackduedate);
                $item->feedbackduedateraw = $record->feedbackduedate;
                $item->feedbackduedate = userdate(
                    $record->feedbackduedate,
                    get_string('strftimedatemonthabbr', 'langconfig')
                );

                // Get a custom feedback due date reason entry for the grade item where available.
                $item->feedbackduedatereason = s(self::get_reason($item->gradeitemid, $item->partid, $item->feedbackduedate));
            }

            // Check if there is additional data to show.
            if ($item->generalfeedback || $item->method || $item->contact) {
                $item->additionaldata = true;
            }

            if ($record->gfdate) {
                $item->customfeedbackreleaseddateraw = $record->gfdate;
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
     * @param int $userid optional user ID to get submission dates
     * @return void
     */
    public static function add_ttt_data(
        stdClass $data,
        grade_item $gradeitem,
        stdClass $item,
        int $userid = 0
    ): void {
        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');
        $tttparts = self::get_turnitin_parts($gradeitem->iteminstance);

        foreach ($tttparts as $tttpart) {
            // Each part of a turnitintooltwo assessment starts as a clone of the module
            // and adds data related to each part.
            $tttitem = clone $item;
            $tttitem->name = $gradeitem->itemname . " - " . $tttpart->partname;
            $tttitem->partid = $tttpart->id;

            // Get the due date for each part.
            $duedate = $tttpart->dtdue;
            if ($userid) {
                // If there is a user ID add the submission and grading data for that user.
                student::add_user_data($userid, $tttitem, $gradeitem, $duedate, $tttitem->partid);
            }

            // The feedback due date timestamp is needed for sorting.
            if (!$duedate) {
                $tttitem->feedbackduedateraw = 9999999999;
                $tttitem->feedbackduedate = false;
                $tttitem->duedate = false;
            } else {
                $tttitem->feedbackduedateraw = self::get_feedbackduedate($gradeitem, $duedate);
                $tttitem->feedbackduedate = userdate($tttitem->feedbackduedateraw, $dateformat);
                $tttitem->duedate = userdate($duedate, $dateformat);
                if ($userid) { // Feedback status is for user reports only.
                    $tttitem->feedbackstatus = self::get_feedback_status($gradeitem, $tttitem);
                }
            }

            self::add_additional_data($tttitem);
            $data->items[$tttitem->partid] = $tttitem;
        }
    }

    /**
     * Get the current academic year.
     *
     * @return int
     */
    public static function get_current_academic_year(): int {
        $clock = \core\di::get(\core\clock::class);
        if (defined('BEHAT_SITE_RUNNING')) {
            return $clock->now()->format('Y');
        }
        $currentyear = $clock->now()->format('Y');
        $currentmonth = $clock->now()->format('n');
        return $currentmonth >= self::AY_START_MONTH ? $currentyear : $currentyear - 1; // Academic Year begins 1st of October.
    }

    /**
     * Menu data for a report.
     *
     * @param string $reporttype
     * @return stdClass The menu data.
     */
    public static function menu(string $reporttype): stdClass {
        $params = [];
        $reporturl = "/report/feedback_tracker/$reporttype.php";
        $clock = \core\di::get(\core\clock::class);

        // Template for mustache.
        $template = new stdClass();

        if ($reporttype === 'site') {
            $params['term'] = optional_param('term', self::current_term($clock->now()->format('n')), PARAM_INT);
            $template->term = $params['term'];
        }

        // Check if we have a year from url, or set a default.
        $defaultyear = self::get_current_academic_year();
        $params['year'] = optional_param('year', $defaultyear, PARAM_INT);

        // Check if we have an assessment type from url, or set summative as default.
        $params['type'] = optional_param('type', assess_type::ASSESS_TYPE_SUMMATIVE + 1, PARAM_INT);

        $template->year = $params['year'];
        $template->type = $params['type'];

        // Years menu.
        $yearmenu = new stdClass();
        $yearmenu->type = get_string('year', 'report_feedback_tracker');
        $yearmenu->items = self::years_menu($params, $reporturl);
        $template->menus[] = $yearmenu;
        // Terms menu.
        if ($reporttype === 'site') {
            $termmenu = new stdClass();
            $termmenu->type = get_string('term', 'report_feedback_tracker');
            $termmenu->items = self::terms_menu($params, $reporturl);
            $template->menus[] = $termmenu;
        }

        // Type menu.
        $typemenu = new stdClass();
        $typemenu->type = get_string('type', 'report_feedback_tracker');
        $typemenu->items = self::types_menu($params, $reporturl);
        $template->menus[] = $typemenu;

        return $template;
    }

    /**
     * Return the current academic term.
     *
     * @param int $month The current month (1-12).
     * @return int The current term (1-3).
     */
    private static function current_term(int $month): int {
        if ($month <= 3) {
            return self::TERM_SPRING;
        }
        if ($month <= 8) {
            return self::TERM_SUMMER;
        }
        return self::TERM_AUTUMN;
    }

    /**
     * Years menu.
     *
     * @param array $params The year menu data.
     * @param string $reporturl return URL of given report.
     * @return array The years menu data.
     */
    private static function years_menu(array $params, string $reporturl): array {
        // Menu start year.
        $menustart = self::get_current_academic_year();
        $selected = $params['year'];

        $years = [];
        for ($params['year'] = $menustart; $params['year'] > $menustart - 3; $params['year']--) {
            $template = new stdClass();
            $template->value = substr($params['year'], -2) . "/" . substr($params['year'] + 1, -2);
            $template->url = new moodle_url($reporturl, $params);
            $template->selected = ($selected === $params['year']);
            $years[] = $template;
        }
        return $years;
    }

    /**
     * Terms menu.
     *
     * @param array $params The terms menu data.
     * @param string $reporturl return URL of given report.
     * @return array The terms menu data.
     */
    private static function terms_menu(array $params, string $reporturl): array {
        $terms = [];
        $selected = $params['term'];

        for ($params['term'] = 1; $params['term'] <= 4; $params['term']++) {
            $template = new stdClass();
            $template->value = get_string('t' . $params['term'], 'report_feedback_tracker');
            $template->url = new moodle_url($reporturl, $params);
            $template->selected = ($selected === $params['term']);
            $terms[] = $template;
        }

        return $terms;
    }

    /**
     * Assessment types menu.
     *
     * @param array $params The types menu data.
     * @param string $reporturl returt URL of given report.
     * @return array The assessment types menu data.
     */
    private static function types_menu(array $params, string $reporturl): array {
        $selected = $params['type'];

        // Summative.
        $params['type'] = 2;
        $template = new stdClass();
        $template->value = get_string('summative', 'local_assess_type');
        $template->url = new moodle_url($reporturl, $params);
        $template->selected = ($selected === $params['type']);
        $types[] = $template;

        // Formative.
        $params['type'] = 1;
        $template = new stdClass();
        $template->value = get_string('formative', 'local_assess_type');
        $template->url = new moodle_url($reporturl, $params);
        $template->selected = ($selected === $params['type']);
        $types[] = $template;

        // All.
        $params['type'] = 3;
        $template = new stdClass();
        $template->value = get_string('all');
        $template->url = new moodle_url($reporturl, $params);
        $template->selected = ($selected === $params['type']);
        $types[] = $template;

        return $types;
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

        return (int)$DB->get_field_sql($sql, $params);
    }

    /**
     * Get a feedback status for a given grade item..
     *
     * @param grade_item $gradeitem
     * @param stdClass $record
     * @return array
     */
    public static function get_feedback_status(grade_item $gradeitem, stdClass $record): array {

        // If a grade item has not (yet) been released do not show a badge.
        if (($gradeitem->hidden == 1) || ($gradeitem->hidden > time())) {
            return [];
        }

        $submissiondate = $record->submissiondate;
        $feedbackduedate = $record->feedbackduedateraw;
        $feedbackdate = $record->feedbackdate;

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
        } else if (!empty($record->customfeedbackreleaseddate)) {
            // No submission and no feedback due date but a custom feedback released date - show released in time.
            return ['released' => 'released'];
        } else { // No submission or no feedback due date or still within feedback period - show nothing.
            return [];
        }
    }

    /**
     * Make and open a file for writing.
     * @param string $path The full path to the file to create and open.
     * @return resource
     * @throws moodle_exception
     */
    public static function make_and_open_file(string $path): mixed {

        // Open the file.
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new moodle_exception('data:open_file_error', 'report_feedback_tracker', null, $path);
        }

        fwrite($handle, "[\n");
        return $handle;
    }

    /**
     * Write the final line and close the file.
     *
     * @param mixed $handle File resource
     * @return void
     */
    public static function close_file(mixed $handle): void {
        fwrite($handle, "\n]");
        fclose($handle);
    }

    /**
     * Write a JSON record to a given file
     *
     * @param mixed $handle File resource
     * @param string $data Data to write
     * @param int $index Record index number
     * @return void
     */
    public static function write_json_record(mixed $handle, string $data, int $index): void {
        if ($index > 0) {
            fwrite($handle, ",\n");
        }
        fwrite($handle, $data);
    }

    /**
     * Get a course module from a given grade item.
     *
     * @param grade_item $gradeitem
     * @param course_modinfo $modinfo
     * @return false|mixed
     */
    public static function get_module_from_gradeitem(grade_item $gradeitem, course_modinfo $modinfo) {
        global $DB;

        $sql = "
                SELECT cm.id AS cmid
                FROM {course_modules} cm
                JOIN {modules} m ON cm.module = m.id
                JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
                WHERE gi.id = :gradeitemid
            ";

        // Verify $modinfo->cms item in case the module data is missing.
        if (($cmid = $DB->get_field_sql($sql, ['gradeitemid' => $gradeitem->id])) && isset($modinfo->cms[$cmid])) {
            return $modinfo->get_cm($cmid);
        } else {
            return false;
        }
    }
}
