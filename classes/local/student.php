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

        // Year and assessment type menus.
        $menus = helper::menu('student');
        $data->menus = $menus->menus;

        // Get the user information.
        $user = get_complete_user_data('id', $userid);
        $data->userfirstname = $user->firstname;
        $data->userlastname = $user->lastname;
        $data->student = $user->username;

        // If a valid course ID is given return data for that course only
        // otherwise return data for all courses with an active enrolment for a user.
        if ($courseid === SITEID) {
            $courses = enrol_get_all_users_courses($userid, true, null, 'fullname');

            // Show academic year options when showing all courses.
            $year = $menus->year;

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
            usort($data->courses, function ($a, $b) {
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
        if (PHPUNIT_TEST) {
            $selectedassesstype = 2; // Select all assessment types for PHPUnit tests.
        } else {
            $selectedassesstype = optional_param('type', 2, PARAM_INT) - 1;
        }

        helper::$assesstypes = helper::get_assessment_types($course->id);

        $courseitem = new stdClass();
        $courseitem->url = helper::get_course_url($course->id);
        $courseitem->image = course_summary_exporter::get_course_image($course);
        $courseitem->fullname = $course->fullname;

        foreach ($gradeitems as $gradeitem) {
            // Process modules and manual grade items only.
            if (
                ((($gradeitem->itemtype === 'mod') && helper::is_supported_module($gradeitem->itemmodule)) ||
                    (($gradeitem->itemtype === 'manual') && helper::is_supported_module($gradeitem->itemtype))) &&
                    $item = self::build_gradeitem($modinfo, $gradeitem, $userid)
            ) {
                // Get course module ID for the grade item where it exists.
                $cmid = helper::get_cmid($gradeitem->id);

                // Show selected assessment type(s) only.
                $assesstype = helper::get_assesstype($gradeitem->id, $cmid);
                if (($selectedassesstype !== helper::ASSESS_TYPE_ALL) && ($assesstype->type != $selectedassesstype)) {
                    continue;
                }

                // Skip hidden items.
                if ($item->hiddenfromreport) {
                    continue;
                }

                if ($gradeitem->itemmodule === 'turnitintooltwo') {
                    helper::add_ttt_data($courseitem, $gradeitem, $item, $userid);
                } else {
                    $courseitem->items[] = $item;
                }
            }
        }

        // Sort assessments by feedback due date.
        if (isset($courseitem->items)) {
            usort($courseitem->items, function ($a, $b) {
                return $a->feedbackduedateraw <=> $b->feedbackduedateraw;
            });
        }

        return $courseitem;
    }

    /**
     * Build a module item or a manual item.
     *
     * @param course_modinfo $modinfo
     * @param grade_item $gradeitem
     * @param int $userid
     * @return false|stdClass
     */
    private static function build_gradeitem(course_modinfo $modinfo, grade_item $gradeitem, int $userid): false|stdClass {
        global $DB;

        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');
        $data = new stdClass();

        if ($gradeitem->itemtype === "manual") {
            if ($gradeitem->is_hidden()) {
                return false;
            }

            // Manual grade items have no module and therefore no direct link.
            $data->url = new moodle_url(
                '/course/user.php',
                ['mode' => 'grade', 'id' => $gradeitem->courseid, 'user' => $userid]
            );
            $duedate = 0;
            $data->cmid = 0;
            $data->submissiondate = 0;
        } else if ($module = helper::get_module_from_gradeitem($gradeitem, $modinfo)) {
            // Check if module is visible.
            if (!$module->uservisible) {
                return false;
            }

            $cm = $modinfo->get_cm($module->id);
            $data->url = $cm->get_url();
            $data->moduletypeiconurl = $module->get_icon_url()->out(false);
            $data->cmid = $module->id;

            $duedate = module_helper_factory::create($module)->get_user_duedate($gradeitem, $userid);

            // Add submission and grading for the user.
            self::add_user_data($userid, $data, $gradeitem, $duedate);
        } else { // There is no course module for the grade item.
            return false;
        }

        $data->gradeitemid = $gradeitem->id;
        $data->name = $gradeitem->itemname;
        $data->partid = null;
        $data->markoverdue = false;

        // Assessment type.
        $assesstype = helper::get_assesstype($gradeitem->id, $data->cmid);
        helper::add_assesstype($data, $assesstype);

        // Show the assessment end date as due date when dealing with the assessment part of workshops.
        if (self::is_workshop_assessment($gradeitem)) {
            $params = ['id' => $gradeitem->iteminstance];
            $duedate = $DB->get_field('workshop', 'assessmentend', $params);
        }

        if ($duedate) {
            $data->duedate = userdate($duedate, $dateformat);
            $data->feedbackduedateraw = helper::get_feedbackduedate($gradeitem, $duedate);
            $data->feedbackduedate = userdate($data->feedbackduedateraw, $dateformat);
        } else {
            $data->duedate = ($gradeitem->itemtype === "manual") ? "" : get_string('datenotset', 'report_feedback_tracker');
            $data->feedbackduedateraw = 9999999999;
            $data->feedbackduedate = get_string('datenotset', 'report_feedback_tracker');
        }

        // Grading.
        $gradingrecord = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
        if (!$gradingrecord || $gradeitem->is_hidden()) {
            // No grading record or grade not (yet) released.
            $data->grade = null;
        } else {
            $data->grade = self::get_grading($gradingrecord);
        }
        $feedbackdate = $gradingrecord->timemodified ?? 0;

        helper::add_additional_data($data);
        $data->feedbackdate = $data->customfeedbackreleaseddateraw ?? $feedbackdate;
        $data->feedbackstatus = helper::get_feedback_status($gradeitem, $data);

        return $data;
    }

    /**
     * Check if a gradeitem is a workshop assignment.
     *
     * @param grade_item $gradeitem
     * @return bool
     */
    private static function is_workshop_assessment(grade_item $gradeitem): bool {
        // For workshop grade items itemnumber == 1 points to the assessment part.
        return ($gradeitem->itemmodule === 'workshop') && ($gradeitem->itemnumber == 1);
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
        $cm = \cm_info::create(get_coursemodule_from_instance($gradeitem->itemmodule, $gradeitem->iteminstance));

        $data->submissiondate = module_helper_factory::create($cm)->get_submissiondate($userid, $gradeitem->iteminstance, $part);
        if (self::is_workshop_assessment($gradeitem)) {
            $data->workshopassessmentstatus = self::get_workshop_assessment_status($userid, $gradeitem->iteminstance);
        } else {
            $data->submissionstatus = self::get_submission_status($duedate, $data->submissiondate);
        }
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
        if ($duedate && !$submissiondate && time() > $duedate) {
            return ['overdue' => 'overdue'];
        }

        // The submission is not due yet - so return nothing.
        return [];
    }

    /**
     * Return a workshop assessment status badge.
     *
     * @param int $userid
     * @param int $instance
     * @return string[]
     */
    private static function get_workshop_assessment_status(int $userid, int $instance): array {
        global $DB;

        // Get workshop assessments for the user.
        $params = ['workshopid' => $instance, 'userid' => $userid];
        $sql = "SELECT wa.*, ws.assessmentstart, ws.assessmentend
                FROM {workshop_assessments} wa
                JOIN {workshop_submissions} wsub ON wsub.id = wa.submissionid
                JOIN {workshop} ws ON ws.id = wsub.workshopid
                WHERE ws.id = :workshopid
                AND wa.reviewerid = :userid";

        $assessments = $DB->get_records_sql($sql, $params);

        // No assesments.
        if (!$assessments) {
            return [];
        }

        // At least one assessment w/o a grade.
        if (in_array(null, array_column($assessments, 'grade'), true)) {
            $badges = [];

            foreach ($assessments as $assessment) {
                $assessmentdate = $assessment->timemodified;
                $duedate = $assessment->assessmentend;
                $assessmentstart = $assessment->assessmentstart;

                // There is an assessment, and it was in time or there is no due date: success!
                if ($assessmentdate && ($assessmentdate <= $duedate || !$duedate)) {
                    $badges['success'] = isset($badges['success']) ? $badges['success'] + 1 : 1;
                } else if ($duedate && $assessmentdate > $duedate) {
                    // Assessment was late.
                    $badges['late'] = isset($badges['late']) ? $badges['late'] + 1 : 1;
                } else if ($duedate && !$assessmentdate && time() > $duedate) {
                    // NO assessment and the due date has passed.
                    $badges['overdue'] = isset($badges['overdue']) ? $badges['overdue'] + 1 : 1;
                } else if (!$assessmentstart || ($assessmentstart < time())) {
                    // No assessment but within time.
                    // If the assessment start date has passed or there is no assessment start date show a reminder.
                    $badges['due'] = isset($badges['due']) ? $badges['due'] + 1 : 1;
                }
            }
            return $badges;
        }

        // All asssessments submitted.
        return ['allsuccess' => 'allsuccess'];
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

        return $DB->get_fieldset_select(
            'role',
            'id',
            'archetype IN (:role1)',
            [
                'role1' => 'student',
            ]
        );
    }
}
