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
use cm_info;
use core_course\external\course_summary_exporter;
use course_modinfo;
use grade_item;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;

/**
 * Feedback tracker site level report for students.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student {

    /**
     * @var array of assessment types.
     */
    private static array $assesstypes;

    /**
     * @var array array of roles a student may have.
     */
    private static array $studentroles;

    /**
     * Get the Feedback tracker data for one or all courses of a given user.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass
     */
    public static function get_feedback_tracker_student_data($userid, $courseid): stdClass {
        $data = new stdClass();
        $data->viewasstudent = true;
        $data->studentdata = true;
        $data->items = [];
        $data->courses = [];
        self::$studentroles = self::get_student_role_ids();

        // Get the user information.
        $user = get_complete_user_data('id', $userid);
        $data->userfirstname = $user->firstname;
        $data->userlastname = $user->lastname;
        $data->student = $user->username;

        // If a valid course ID is given return data for that course only
        // otherwise return data for all courses a user is enrolled in.
        if ($courseid === SITEID) {
            $courses = enrol_get_all_users_courses($userid, false, null, 'fullname');

            // Show academic year options when showing all courses.
            // Get the academic years of courses the user is enrolled into.
            $data->hasyears = true;
            $academicyears = helper::get_academic_years_from_courses($courses);
            if ($academicyears) {
                $data->academicyearoptions = $academicyears;
            }

            $year = optional_param('year', self::get_default_academicyear($academicyears), PARAM_INT);

            // Remove the key of the academic year to show.
            // This is used by the template to identify the year selected.
            foreach ($academicyears as $academicyear) {
                if ($academicyear->key === $year) {
                    unset($academicyear->key);
                }
            }

            foreach ($courses as $course) {
                // Show courses where the user is a student from the selected academic year.
                if (self::is_course_student($course) && $year === helper::get_academic_year($course->id)) {
                    $courseitem = self::build_courseitem($course, $userid);
                    // Show only courses with assessments.
                    if (isset($courseitem->items)) {
                        $data->courses[] = $courseitem;
                    }
                }
            }

            // Sort the courses by name.
            usort($data->courses, function($a, $b) {
                return strcmp($a->fullname, $b->fullname);
            });
        } else {
            $course = get_course($courseid);
            $courseitem = self::build_courseitem($course, $userid);
            // Show only courses with assessments.
            if (isset($courseitem->items)) {
                $courseitem->viewasstudent = true;
                $data->courses[] = $courseitem;
                $data->items = $courseitem->items;
            }
        }
        return $data;
    }

    /**
     * Build a course item.
     *
     * @param stdClass $course
     * @param int $userid
     * @return stdClass|null
     */
    private static function build_courseitem(stdClass $course, int $userid): ?stdClass {
        $gradeitems = grade_item::fetch_all(['courseid' => $course->id]);
        // Only build course items from courses with grade items.
        if (!$gradeitems) {
            return null;
        }

        $modinfo = get_fast_modinfo($course->id);
        self::$assesstypes = helper::get_assessment_types($course->id);

        $courseitem = new stdClass();
        $courseitem->url = helper::get_course_url($course->id);
        $courseitem->image = course_summary_exporter::get_course_image($course);
        $courseitem->fullname = $course->fullname;

        foreach ($gradeitems as $gradeitem) {
            // Get course module ID for the grade item where it exists.
            $cmid = helper::get_cmid($gradeitem->id);
            $assesstype = helper::get_assesstype($gradeitem->id,  $cmid, self::$assesstypes);

            // Process modules and manual grade items only.
            if (((($gradeitem->itemtype === 'mod') && helper::is_supported_module($gradeitem->itemmodule)) ||
                    (($gradeitem->itemtype === 'manual') && helper::is_supported_module($gradeitem->itemtype))) &&
                    $item = self::build_moduleitem($modinfo, $gradeitem, $userid)) {

                // Skip hidden items.
                if ($item->hiddenfromreport) {
                    continue;
                }

                if ($gradeitem->itemmodule === 'turnitintooltwo') {
                    helper::add_ttt_data($courseitem, $gradeitem, $item, self::$assesstypes, $userid);
                } else {
                    $courseitem->items[] = $item;
                }
            }
        }

        // Sort assessments by feedback due date.
        if (isset($courseitem->items)) {
            usort($courseitem->items, function($a, $b) {
                return $a->feedbackduedateraw <=> $b->feedbackduedateraw;
            });
        }

        return $courseitem;
    }

    /**
     * Build a module item.
     *
     * @param course_modinfo $modinfo
     * @param grade_item $gradeitem
     * @param int $userid
     * @return false|stdClass
     */
    private static function build_moduleitem(course_modinfo $modinfo, grade_item $gradeitem, int $userid): false|stdClass {
        global $USER;

        // Manual grade items are different.
        if ($gradeitem->itemtype === "manual") {
            return self::build_manualitem($gradeitem, $userid);
        }

        if ($cm = admin::get_cm_from_gradeitem($gradeitem)) {
            // Get the module and check if it is visible.
            $module = $modinfo->get_cm($cm->cmid);
            if (!$module->visible) {
                return false;
            }
        } else { // There is no course module for the grade item.
            return false;
        }

        $customdata = $module->customdata;

        // Assignment modules may have NO submission type - if so don't show them here.
        if ($module->modname === 'assign' && !helper::count_assign_submission_plugins($module->id)) {
            return false;
        }

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');

        // Build the module data.
        $data = new stdClass();
        $data->cmid = $module->id;
        $data->gradeitemid = $gradeitem->id;
        $data->modname = $module->modname;
        $data->name = $gradeitem->itemname; // The grade item name has more details than the module name.
        $data->url = self::get_module_url($module);
        $data->moduletypeiconurl = $module->get_icon_url()->out(false);

        $data->partid = null;

        // Assessment type.
        $assesstype = helper::get_assesstype($gradeitem->id, $module->id, self::$assesstypes);
        $data->formative = ($assesstype->type == assess_type::ASSESS_TYPE_FORMATIVE);
        $data->summative = ($assesstype->type == assess_type::ASSESS_TYPE_SUMMATIVE);
        $data->dummy = ($assesstype->type == assess_type::ASSESS_TYPE_DUMMY);

        // Due dates.

        // Different modules use different field names for the due date.
        $duedates = [
            'assign' => 'duedate',
            'lesson' => 'deadline',
            'quiz' => 'timeclose',
        ];

        if (is_array($customdata)
            && array_key_exists($module->modname, $duedates)
            && isset($customdata[$duedates[$module->modname]])) {
            // Due to a core bug $customdata will always contain data for $USER->id, regardless of $userid given.
            // See MDL-83121.
            // The module customdata does not contain any optional assignment extensions so using custom method.
            if ($USER->id === $userid && $module->modname !== 'assign') {
                $duedate = $customdata[$duedates[$module->modname]];
            } else {
                // Use a custom method to get the override for a student user shown in an admin report.
                $duedate = self::get_user_duedate($gradeitem, $userid) ?: admin::get_duedate($module);
            }
        } else {
            $duedate = admin::get_duedate($module);
        }

        if ($duedate) {
            $data->duedate = userdate($duedate, $dateformat);
            $data->feedbackduedateraw = helper::get_feedbackduedate($gradeitem, $duedate);
            $data->feedbackduedate = userdate($data->feedbackduedateraw, $dateformat);
        } else {
            $data->duedate = get_string('datenotset', 'report_feedback_tracker');
            $data->feedbackduedateraw = 9999999999;
            $data->feedbackduedate = get_string('datenotset', 'report_feedback_tracker');
        }
        $data->markoverdue = false;

        // Add submission and grading for the user.
        self::add_user_data($userid, $data, $gradeitem, $duedate);

        return $data;
    }

    /**
     * Add submission and grading for the given user to the data.
     *
     * @param int $userid
     * @param stdClass $data
     * @param grade_item $gradeitem
     * @param int $duedate
     * @param int $part Part number of turnitintooltwo assessments
     * @return void
     */
    public static function add_user_data(int $userid, stdClass $data, grade_item $gradeitem, int $duedate, int $part = 0) {
        global $DB;

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');

        // Submission.
        $submissiondate = self::get_submissiondate($userid, $gradeitem->itemmodule, $gradeitem->iteminstance, $part);
        $data->submissiondate = $submissiondate == 0 ? '--' : userdate($submissiondate, $dateformat);
        $data->submissionstatus = self::get_submission_status($duedate, $submissiondate);

        // Grading.
        $gradingrecord = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
        $data->grade = $gradingrecord ? self::get_grading($gradingrecord) : null;
        $feedbackdate = $gradingrecord->timemodified ?? 0;
        // Add manual set dates and additional data.
        helper::add_additional_data($data);
        $data->feedbackstatus = self::get_feedback_status($gradeitem, $submissiondate, $data->feedbackduedateraw, $feedbackdate);
    }

    /**
     * Get the submission date for a grade item and student if any.
     *
     * @param int $userid
     * @param string $moduletype
     * @param int $instance
     * @param int $part turnitintooltwo part number
     * @return int
     */
    private static function get_submissiondate(int $userid, string $moduletype, int $instance, int $part): int {
        global $DB;

        switch ($moduletype) {
            case 'assign':
                $params = ['userid' => $userid, 'instance' => $instance];
                $sql = "SELECT MAX(timemodified)
                        FROM {assign_submission}
                        WHERE userid = :userid
                        AND assignment = :instance
                        AND status = 'submitted'";
                break;
            case 'lesson':
                $params = ['userid' => $userid, 'instance' => $instance];
                $sql = "SELECT MAX(timeseen)
                        FROM {lesson_attempts}
                        WHERE userid = :userid
                        AND lessonid = :instance";
                break;
            case 'quiz':
                $params = ['userid' => $userid, 'instance' => $instance];
                $sql = "SELECT MAX(timefinish)
                        FROM {quiz_attempts}
                        WHERE userid = :userid
                        AND quiz = :instance
                        AND state = 'finished'";
                break;
            case 'turnitintooltwo':
                $sql = "SELECT MAX(submission_modified)
                          FROM {turnitintooltwo_submissions}
                         WHERE userid = :userid
                               AND turnitintooltwoid = :instance";
                $params = ['userid' => $userid, 'instance' => $instance];

                if ($part) {
                    $sql .= " AND submission_part = :part";
                    $params['part'] = $part;
                }

                break;
            case 'workshop':
                $params = ['userid' => $userid, 'instance' => $instance];
                $sql = "SELECT MAX(timemodified)
                        FROM {workshop_submissions}
                        WHERE authorid = :userid
                        AND workshopid = :instance";
                break;
            default:
                return 0;
        }
        return $DB->get_field_sql($sql, $params) ?? 0;
    }

    /**
     * Return a submission status badge.
     *
     * @param int $duedate
     * @param int $submissiondate
     * @param int $warningperiod optional warning period, unused for now.
     * @return string
     */
    private static function get_submission_status(int $duedate, int $submissiondate, int $warningperiod = 0): array {
        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');

        // There is a submission, and it was in time or there is no due date.
        if ($submissiondate && ($submissiondate <= $duedate || !$duedate)) {
            return ['success' => 'success'];
        }

        // Submission was late.
        if ($duedate && $submissiondate && $submissiondate > $duedate) {
            return ['late' => 'late'];
        }

        // NO submission but approaching due date within warning period. Unused for now.
        if (!$submissiondate && time() <= $duedate && time() >= $duedate - $warningperiod && false) {
            return ['warning' => 'warning'];
        }

        // NO submission and the due date has passed.
        if ($duedate && !$submissiondate && time() > $duedate ) {
            return ['overdue' => 'overdue'];
        }

        // The submission is not due yet - so return nothing.
        return [];
    }

    /**
     * Build a manual grade item.
     *
     * @param grade_item $gradeitem
     * @param int $userid
     * @return stdClass|false
     */
    private static function build_manualitem(grade_item $gradeitem, int $userid): stdClass|false {
        global $DB;

        // If a manual grade item has not (yet) been released do not show it.
        if (($gradeitem->hidden == 1) || ($gradeitem->hidden > time())) {
            return false;
        }

        $data = new stdClass();
        $data->gradeitemid = $gradeitem->id;
        $data->name = $gradeitem->itemname;

        // Manual grade items have no module and therefore no direct link.
        $data->url = new moodle_url('/course/user.php',
            ['mode' => 'grade', 'id' => $gradeitem->courseid, 'user' => $userid]);
        $data->partid = null;

        // Assessment type.
        $assesstype = helper::get_assesstype($gradeitem->id, 0, self::$assesstypes);
        $data->formative = ($assesstype->type == assess_type::ASSESS_TYPE_FORMATIVE);
        $data->summative = ($assesstype->type == assess_type::ASSESS_TYPE_SUMMATIVE);
        $data->dummy = ($assesstype->type == assess_type::ASSESS_TYPE_DUMMY);

        // Due dates.
        $data->duedate = '';
        // The raw date is needed for sorting.
        $data->feedbackduedateraw = 9999999999;
        $data->feedbackduedate = get_string('datenotset', 'report_feedback_tracker');
        $data->markoverdue = false;

        // Grading.
        $gradingrecord = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
        $feedbackdate = $gradingrecord->timemodified ?? 0;
        $data->grade = $gradingrecord ? self::get_grading($gradingrecord) : null;
        helper::add_additional_data($data);
        $data->feedbackstatus = self::get_feedback_status($gradeitem, 0, $data->feedbackduedateraw, $feedbackdate);

        return $data;
    }

    /**
     * Return a grading string using the final grade or false if there is no final grade.
     *
     * @param stdClass $gradingrecord
     * @return string|false
     */
    private static function get_grading(stdClass $gradingrecord): string|false {
        global $DB;
        if (!$gradingrecord->finalgrade || ($gradingrecord->hidden === 1) || ($gradingrecord->hidden > time())) {
            // No final grade or grade not (yet) released.
            return false;
        }

        // Check for scales.
        if ($gradingrecord->rawscaleid) {
            $scaledefs = $DB->get_field('scale', 'scale', ['id' => $gradingrecord->rawscaleid]);
            $scaleoptions = explode(',', $scaledefs);
            return $scaleoptions[$gradingrecord->finalgrade - 1];
        } else {
            return round($gradingrecord->finalgrade) . '/' . round($gradingrecord->rawgrademax);
        }
    }

    /**
     * Get a feedback status for a given grade item..
     *
     * @param grade_item $gradeitem
     * @param int $submissiondate
     * @param int $feedbackduedate
     * @param int $feedbackdate
     * @return array
     */
    private static function get_feedback_status(grade_item $gradeitem, int $submissiondate, int $feedbackduedate,
                                                int $feedbackdate): array {

        // If a grade item has not (yet) been released do not show a badge.
        if (($gradeitem->hidden == 1) || ($gradeitem->hidden > time())) {
            return [];
        }

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
     * Return a URL to the module item where applicable or to the gradebook otherwise.
     *
     * @param cm_info $module
     * @return string
     */
    private static function get_module_url(cm_info $module): string {
        global $CFG, $USER;

        if (!$module) {
            return "$CFG->wwwroot/grade/report/student/index.php?id=$module->course&userid=$USER->id";
        }
        return "$CFG->wwwroot/mod/$module->modname/view.php?id=$module->id";
    }

    /**
     * Get the academic year to show to the student by default.
     *
     * @param array $academicyears
     * @return int
     */
    private static function get_default_academicyear(array $academicyears): int {
        if ($academicyears) {
            // Return the last academic year the user has been enrolled into a course.
            return $academicyears[0]->key;
        } else {
            // The user has not been enrolled into any course yet so return the current academic year.
            return helper::get_current_academic_year();
        }
    }

    /**
     * Return if user has required student role in given course.
     *
     * @param stdClass $course
     * @return bool
     */
    private static function is_course_student(stdClass $course): bool {
        global $USER;

        // Check if user has a student role in the given course.
        foreach (self::$studentroles as $role) {
            if (user_has_role_assignment($USER->id, (int)$role, $course->ctxid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the student role IDs.
     *
     * @return array
     */
    private static function get_student_role_ids(): array {
        global $DB;

        return $DB->get_fieldset_select('role', 'id',
            'archetype IN (:role1)',
            [
                'role1' => 'student',
            ]
        );
    }

    /**
     * Get a due date for a user including optional overrides and extensions.
     *
     * @param grade_item $gradeitem
     * @param int $userid
     * @return false|int
     */
    private static function get_user_duedate(grade_item $gradeitem, int $userid): false|int {
        global $DB;

        switch ($gradeitem->itemmodule) {
            case 'assign':
                // Get individual override where available.
                $params = ['assignid' => $gradeitem->iteminstance, 'userid' => $userid];
                $overridedate = $DB->get_field('assign_overrides', 'duedate', $params);

                $usergroups = groups_get_user_groups($gradeitem->courseid, $userid);

                // If there is no individual override check for a group override date.
                if (!$overridedate) {
                    foreach ($usergroups[0] as $usergroupid) {
                        $params = ['assignid' => $gradeitem->iteminstance, 'groupid' => $usergroupid];
                        $overrideduedate = $DB->get_field('assign_overrides', 'duedate', $params);

                        if ($overrideduedate > $overridedate) {
                            $overridedate = $overrideduedate;
                        }
                    }
                }

                // Get individual extension where available.
                $params = ['assignment' => $gradeitem->iteminstance, 'userid' => $userid];
                $extensiondate = $DB->get_field('assign_user_flags', 'extensionduedate', $params);

                // Use the date that gives the most time to the student.
                if ($extensiondate > $overridedate) {
                    $overridedate = $extensiondate;
                }

                break;
            case 'lesson':
                $params = ['lessonid' => $gradeitem->iteminstance, 'userid' => $userid];
                $overridedate = $DB->get_field('lesson_overrides', 'deadline', $params);
                break;
            case 'quiz':
                $params = ['quiz' => $gradeitem->iteminstance, 'userid' => $userid];
                $overridedate = $DB->get_field('quiz_overrides', 'timeclose', $params);
                break;
            default:
                $overridedate = false;
                break;
        }
        return  $overridedate ?: false;
    }

}
