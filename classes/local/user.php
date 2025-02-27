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
use html_writer;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;

/**
 * This file contains the user related functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user {

    /**
     * Get the Feedback tracker data for one or all courses of a given user.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass
     */
    public static function get_feedback_tracker_user_data($userid, $courseid): stdClass {
        $data = new stdClass();
        $data->viewasstudent = true;
        $data->items = [];
        $data->courses = [];
        $enrolledcourses = enrol_get_users_courses($userid);
        // Get the academic years of the user.
        $academicyears = helper::get_academic_years_from_courses($enrolledcourses);

        if (!empty($academicyears)) {
            $data->academicyearoptions = $academicyears;
        }

        // Get the user information.
        $user = get_complete_user_data('id', $userid);
        $data->userfirstname = $user->firstname;
        $data->userlastname = $user->lastname;

        $year = optional_param('year', null, PARAM_INT);
        $year = $year ? substr($year, 0, 4) : helper::get_year_to_show($academicyears);

        // Remove the key of the academic year to show.
        // This is used by the template to identify the year selected.
        foreach ($academicyears as $academicyear) {
            if ($academicyear->key === $year) {
                unset($academicyear->key);
            }
        }

        // If a valid course ID is given return data for that course only
        // otherwise return data for all courses a user is enrolled in.
        if ($courseid === SITEID) {
            $data->hasyears = true; // Only show academic year options when showing all courses.
            foreach ($enrolledcourses as $course) {
                $academicyear = helper::get_academic_year($course->id);

                if ($academicyear === $year) {
                    self::get_user_course_gradings($course, $userid, $data);
                }
            }

            // Sort the courses by name.
            usort($data->courses, function($a, $b) {
                return strcmp($a->fullname, $b->fullname);
            });
        } else {
            $course = get_course($courseid);
            self::get_user_course_gradings($course, $userid, $data);
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
     */
    public static function get_user_course_gradings($course, $userid, stdClass $data): void {
        global $DB, $USER;

        // Note: the uniqueid seems to be necessary for a correct query using Postgres SQL.
        $sql = "
            SELECT
                ROW_NUMBER() OVER (ORDER BY gi.id) AS uniqueid,
                gi.id AS gradeitemid,
                gi.courseid,
                gi.itemname,
                gi.itemtype,
                gi.itemmodule,
                gi.iteminstance,
                gi.hidden AS hiddengrade,
                u.id AS studentid,
                u.username AS student,
                gi.gradepass,
                gi.grademax,
                CASE
                    WHEN gi.itemmodule = 'assign' THEN
                        (SELECT duedate FROM {assign} WHERE id = gi.iteminstance )
                    WHEN gi.itemmodule = 'lesson' THEN
                        (SELECT deadline FROM {lesson} WHERE id = gi.iteminstance)
                    WHEN gi.itemmodule = 'quiz' THEN
                        (SELECT timeclose FROM {quiz} WHERE id = gi.iteminstance)
                    WHEN gi.itemmodule = 'scorm' THEN
                        (SELECT timeclose FROM {scorm} WHERE id = gi.iteminstance)
                    WHEN gi.itemmodule = 'turnitintooltwo' THEN
                        0
                    WHEN gi.itemmodule = 'workshop' THEN
                        (SELECT submissionend FROM {workshop} WHERE id = gi.iteminstance)
                    ELSE 0
                END AS duedate,
                gg.finalgrade,
                gg.feedback,
                gg.timemodified AS feedbackdate,
                cm.id AS cmid,
                cm.visible,
                um.username AS grader,
                gg.timemodified,
                rft.partid,
                rft.summative,
                rft.hidden,
                rft.feedbackduedate,
                rft.method,
                rft.responsibility,
                rft.generalfeedback,
                rft.gfurl,
                rft.gfdate
            FROM {grade_items} gi
            LEFT JOIN {modules} m ON m.name = gi.itemmodule
            LEFT JOIN {course_modules} cm ON cm.instance = gi.iteminstance AND cm.course = gi.courseid AND m.id = cm.module
            LEFT JOIN {report_feedback_tracker} rft ON rft.gradeitem = gi.id
            LEFT JOIN {grade_grades} gg ON gi.id = gg.itemid AND gg.userid = :userid
            LEFT JOIN {user} u ON u.id = gg.userid
            LEFT JOIN {user} um ON um.id = gg.usermodified
            WHERE gi.courseid = :courseid";

        $params = ['courseid' => $course->id, 'userid' => $userid];
        $gradeitems = $DB->get_records_sql($sql, $params);

        $assesstypes = helper::get_assessment_types($course->id);

        $courseobject = new stdClass();
        $courseobject->courseid = $course->id;
        $courseobject->url = helper::get_course_url($course->id);
        $courseobject->shortname = $course->shortname;
        $courseobject->fullname = $course->fullname;
        $courseobject->image = \core_course\external\course_summary_exporter::get_course_image($course);
        $courseobject->items = [];
        $modinfo = get_fast_modinfo($course->id, $userid);

        // Different modules use different field names for the due date.
        $duedates = [
            'assign' => 'duedate',
            'lesson' => 'deadline',
            'quiz' => 'timeclose',
        ];

        $itemlist = [];
        foreach ($gradeitems as $gradeitem) {
            $gradeitem->hiddengrade = (int) $gradeitem->hiddengrade;
            // Check if the gradeitem module is supported
            // and make sure only one (turnitintooltwo) assessment record is listed even if there are multiple parts.
            if (!helper::module_is_supported($gradeitem) || in_array($gradeitem->gradeitemid, $itemlist)) {
                continue;
            }

            // Get the module information for a grade item.
            if (isset($gradeitem->cmid)) {
                $gradeitem->modinfo = $modinfo->get_cm($gradeitem->cmid);
            }

            // If item is a module (e.g. not manual) check if a user is allowed to access it.
            if ($gradeitem->itemmodule
                    && !$gradeitem->modinfo->uservisible) {
                continue;
            }

            // Get user due dates.
            if (isset($gradeitem->modinfo)) {
                $customdata = $gradeitem->modinfo->customdata;
                $itemmodule = $gradeitem->itemmodule;

                if (is_array($customdata)
                        && array_key_exists($itemmodule, $duedates)
                        && isset($customdata[$duedates[$itemmodule]])) {
                    // Due to a core bug $customdata will always contain data for $USER->id, regardless of $userid given.
                    // See MDL-83121.
                    if ($USER->id === $userid) {
                        $gradeitem->duedate = $customdata[$duedates[$itemmodule]];
                    } else {
                        // Use a custom method to get the override for a student user shown in an admin report.
                        $gradeitem->duedate = self::get_duedate(
                            $gradeitem->courseid,
                            $gradeitem->itemmodule,
                            $gradeitem->iteminstance,
                            $gradeitem->duedate,
                            $userid);
                    }
                }
            }

            // Get the enabled submission types for the assign grade item.
            // Assignments may have NO submission type - we need to know that.
            $gradeitem->submissiontypes = ($gradeitem->itemmodule === 'assign') ?
                helper::get_assign_submission_plugins($gradeitem->cmid) : 0;

            // All good - now get and store the feedback records.
            // Manual grade items have no corresponding course module.
            if ($gradeitem->itemtype === "manual") {
                $gradeitem->cmid = 0;
            }

            // TurnitinToolTwo special treatment as one grading item may have several parts.
            if ($gradeitem->itemmodule == 'turnitintooltwo') {
                self::get_user_turnitin_records($course, $gradeitem, $userid, $assesstypes, $data, $courseobject);
            } else {
                if ($record = self::get_user_feedback_record($course, $userid, $gradeitem, $assesstypes)) {
                    $data->items[] = $record;
                    $courseobject->items[] = $record;
                }
            }
            $itemlist[] = $gradeitem->gradeitemid;
        }

        // Sort the courseobject records by due date.
        if (is_array($courseobject->items)) {
            usort($courseobject->items, function($a, $b) {
                return strcmp($a->duedateraw, $b->duedateraw);
            });
        }

        $data->courses[] = $courseobject;
    }

    /**
     * Get a due date including overrides for a user.
     *
     * @param int $courseid
     * @param string $moduletype e.g. assign, quiz, etc.
     * @param int $instance
     * @param int $duedate
     * @param int $userid
     * @return false|string
     */
    public static function get_duedate($courseid, $moduletype, $instance, $duedate, $userid) {
        global $DB;

        switch ($moduletype) {
            case 'assign':
                // Return individual override when available.
                $params = ['assignid' => $instance, 'userid' => $userid];
                $overridedate = $DB->get_field('assign_overrides', 'duedate', $params);

                // If there is no individual override return group override where available.
                if (!$overridedate) {
                    $usergroups = groups_get_user_groups($courseid, $userid);
                    if (count($usergroups[0]) > 0) {
                        foreach ($usergroups[0] as $usergroupid) {
                            $params = ['assignid' => $instance, 'groupid' => $usergroupid];
                            $overrideduedate = $DB->get_field('assign_overrides', 'duedate', $params);

                            if ($overrideduedate > $overridedate) {
                                $overridedate = $overrideduedate;
                            }
                        }
                    }
                }
                break;
            case 'lesson':
                $params = ['lessonid' => $instance, 'userid' => $userid];
                $overridedate = $DB->get_field('lesson_overrides', 'deadline', $params);
                break;
            case 'quiz':
                $params = ['quiz' => $instance, 'userid' => $userid];
                $overridedate = $DB->get_field('quiz_overrides', 'timeclose', $params);
                break;
            default:
                $overridedate = false;
                break;
        }
        return  $overridedate ?: $duedate;

    }

    /**
     * Get the user feedback record for a grade item.
     *
     * @param stdClass $course
     * @param int $userid
     * @param stdClass $gradeitem
     * @param array $assesstypes
     * @return stdClass|bool
     */
    private static function get_user_feedback_record($course, $userid, $gradeitem, $assesstypes): stdClass|bool {
        $gradeitem->partid = 0; // Only turnitintooltwo assessments may have parts.
        return self::compile_user_data($course, $userid, $gradeitem, $assesstypes);
    }

    /**
     * Get the parts of a turnitintooltwo grading item and list them as separate items.
     *
     * @param stdClass $course
     * @param stdClass $gradeitem
     * @param int $userid
     * @param array $assesstypes
     * @param stdClass $data
     * @param stdClass $courseobject
     * @return void
     */
    private static function get_user_turnitin_records(
      $course,
      $gradeitem,
      $userid,
      $assesstypes,
      $data,
      $courseobject
    ): void {

        $tttparts = helper::get_turnitin_records($course->id);

        foreach ($tttparts[$gradeitem->gradeitemid] as $tttpart) {
            if (!$tttpart->hidden) {
                // Each ttt assessment may have its own attributes.
                $gradeitem->summative = $tttpart->summative;
                $gradeitem->hidden = $tttpart->hidden;
                $gradeitem->duedate = $tttpart->dtdue;
                $gradeitem->feedbackduedate = $tttpart->feedbackduedate;
                $gradeitem->method = $tttpart->method;
                $gradeitem->responsibility = $tttpart->responsibility;
                $gradeitem->generalfeedback = $tttpart->generalfeedback;
                $gradeitem->gfurl = $tttpart->gfurl;
                $gradeitem->gfdate = $tttpart->gfdate;
                $gradeitem->partid = $tttpart->id;
                $gradeitem->partname = helper::get_partname($gradeitem->partid);

                if ($item = self::compile_user_data($course, $userid, $gradeitem, $assesstypes)) {
                    $data->items[] = $item;
                    $courseobject->items[] = $item;
                }
            }
        }
    }

    /**
     * Compile the table record for a student / user.
     *
     * @param stdClass $course
     * @param int $userid
     * @param stdClass $gradeitem
     * @param array $assesstypes
     * @return stdClass|bool
     */
    private static function compile_user_data($course, $userid, $gradeitem, $assesstypes): stdClass|bool {

        // Add the assessment type  where available.
        $assesstype = helper::get_assesstype($gradeitem->gradeitemid, $gradeitem->cmid, $assesstypes);
        helper::add_assesstype($gradeitem, $assesstype);

        // Exclude assessments of type DUMMY.
        if (isset($gradeitem->assesstype) && ((int)$gradeitem->assesstype === assess_type::ASSESS_TYPE_DUMMY)) {
            return false;
        }

        $warningdays = get_config('report_feedback_tracker', 'warningdays');
        $warningperiod = $warningdays ? $warningdays * DAYSECS : 0; // Number of seconds in the warning period.
        $dateformat = get_string('strftimedatemonthabbr', 'langconfig');

        // If there is a manual feedback due date use it, otherwise calculate it from the submission due date where set.
        $feedbackduedate = helper::get_feedbackduedate($gradeitem, $gradeitem->duedate);
        // Get the submission date if any.
        $submissiondate = helper::get_submissiondate($userid, $gradeitem);

        // Data for template.
        $data = new stdClass();
        $data->submissiondate = $submissiondate == 0 ? '--' : userdate($submissiondate, $dateformat);
        $data->submissionstatus = helper::get_submission_status($gradeitem, $submissiondate, $warningperiod);
        $data->courseid = $course->id;
        $data->coursename = $course->fullname;
        $data->cmid = $gradeitem->gradeitemid;
        $data->assessment = helper::get_item_link($gradeitem);
        $data->assesstype = isset($gradeitem->assesstype) ? (int) $gradeitem->assesstype : null;
        $data->moduletypeicon = helper::get_module_type_icon($gradeitem);
        $data->module = helper::get_item_module($gradeitem);
        $data->formative = isset($data->assesstype) &&
            $data->assesstype === assess_type::ASSESS_TYPE_FORMATIVE ? true : false;
        $data->summative = isset($data->assesstype) &&
            $data->assesstype === assess_type::ASSESS_TYPE_SUMMATIVE ? true : false;
        $data->assesstypelabel = helper::get_assesstype_label($data->assesstype);
        $data->duedate = $gradeitem->duedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            userdate($gradeitem->duedate, $dateformat);
        $data->duedateraw = $gradeitem->duedate == 0 ? 9999999999 : $gradeitem->duedate;
        $data->feedbackduedate = $feedbackduedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            userdate($feedbackduedate, $dateformat);
        $data->feedbackduedateraw = $feedbackduedate == 0 ? 9999999999 : $feedbackduedate;
        $data->grade = self::get_grade($gradeitem);
        $data->student = $gradeitem->student;
        $data->grader = $gradeitem->grader;
        $data->feedbackdate = $gradeitem->gfdate ?: $gradeitem->feedbackdate;
        $data->feedback = helper::get_feedback_state($gradeitem, $feedbackduedate, $submissiondate);
        $data->method = $gradeitem->method;
        $data->responsibility = html_writer::div($gradeitem->responsibility);
        $data->generalfeedback = $gradeitem->generalfeedback;
        $data->gfurl = $gradeitem->gfurl;
        $data->contact = $gradeitem->responsibility;
        $data->additionaldata = $data->generalfeedback || $data->method || $data->contact;
        $data->isdummy = isset($data->assesstype) && $data->assesstype == assess_type::ASSESS_TYPE_DUMMY;

        return $data;
    }

    /**
     * Return a grade string using the final grade or false if there is no final grade.
     *
     * @param stdClass $gradeitem
     * @return string|false
     */
    private static function get_grade(stdClass $gradeitem): string|false {
        if (!$gradeitem->finalgrade || ($gradeitem->hiddengrade === 1) || ($gradeitem->hiddengrade > time())) {
            // No final grade or grade not (yet) released.
            return false;
        }
        return round($gradeitem->finalgrade) . '/' . round($gradeitem->grademax);
    }

}
