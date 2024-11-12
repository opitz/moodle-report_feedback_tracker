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
use dml_exception;
use html_writer;
use local_assess_type\assess_type;
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
     * Get the academic years of the user based on the courses s/he is/was enrolled in.
     *
     * @param array $enrolledcourses
     * @return array
     */
    public static function get_user_academic_years(array $enrolledcourses): array {
        $academicyears = [];
        foreach ($enrolledcourses as $course) {
            $academicyear = helper::get_academic_year($course->id);
            if ($academicyear) {
                $obj = new stdClass();
                $obj->key = $academicyear;
                $suffix = (int)substr($academicyear, -2) + 1;
                $obj->value = $academicyear . "-$suffix";
                if (!in_array($obj, $academicyears)) {
                    $academicyears[] = $obj;
                }
            }
        }
        // Sort the years descending.
        if (is_array($academicyears)) {
            usort($academicyears, function($a, $b) {
                return $b->value <=> $a->value; // For descending order.
            });
        }

        return $academicyears;
    }

    /**
     * Get the academic year to show.
     *
     * @param array $academicyears
     * @return int|string
     */
    protected static function get_year_to_show(array $academicyears) {
        if ($academicyears) {
            return max($academicyears)->key; // Return the last academic year the user has been enrolled into a course.
        } else {
            // The user has not been enrolled into any course yet so use the current academic year.
            $currentyear = date('Y');
            $currentmonth = date('m');
            return $currentmonth >= 8 ? $currentyear : $currentyear - 1; // Academic Year begins 1st of August.
        }
    }

    /**
     * Get the Feedback tracker data for one or all courses of a given user.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass
     * @throws coding_exception
     */
    public static function get_feedback_tracker_user_data($userid, $courseid = 0): stdClass {
        $data = new stdClass();
        $data->items = [];
        $data->courses = [];
        $enrolledcourses = enrol_get_users_courses($userid);
        // Get the academic years of the user.
        if ($academicyears = self::get_user_academic_years($enrolledcourses)) {
            $data->academicyearoptions = $academicyears;
            $data->hasyears = true;
        }

        // Get the user information.
        $user = get_complete_user_data('id', $userid);
        $data->userfirstname = $user->firstname;
        $data->userlastname = $user->lastname;

        $year = optional_param('year', null, PARAM_INT);
        $year = $year ? substr($year, 0, 4) : self::get_year_to_show($academicyears);

        // Remove the key of the academic year to show.
        // This is used by the template to identify the year selected.
        foreach ($academicyears as $academicyear) {
            if ($academicyear->key === $year) {
                unset($academicyear->key);
            }
        }

        // Check if we want to show a module header.
        $data->modheader = get_config('report_feedback_tracker', 'modheader');

        // If a course ID is given return data for that course only
        // otherwise return data for all courses a user is enrolled in.
        if ($courseid) {
            unset($data->hasyears); // Do not show academic year options when showing a single course.
            $course = get_course($courseid);
            self::get_user_course_gradings($course, $userid, $data);
        } else {
            foreach ($enrolledcourses as $course) {
                $academicyear = helper::get_academic_year($course->id);

                if ($academicyear === $year) {
                    self::get_user_course_gradings($course, $userid, $data);
                }
            }
        }

        // Sort the courses by name.
        if (is_array($data->courses)) {
            usort($data->courses, function($a, $b) {
                return strcmp($a->fullname, $b->fullname);
            });
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
    public static function get_user_course_gradings($course, $userid, stdClass &$data): void {
        global $DB;

        // Note: the uniqueid seems to be necessary for a correct query using Postgres SQL.
        $sql = "
    select
        ROW_NUMBER() OVER (ORDER BY gi.id) AS uniqueid,
        gi.id as itemid,
        gi.courseid,
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
        rft.partid,
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

        $assessmenttypes = helper::get_assessment_types($course->id);

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
            // Check if the gradeitem module is supported
            // and make sure only one (turnitintooltwo) assessment record is listed even if there are multiple parts.
            if (!helper::module_is_supported($gradeitem) || in_array($gradeitem->itemid, $itemlist)) {
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
                    $gradeitem->duedate = $customdata[$duedates[$itemmodule]];
                }
            }

            // Get the enabled submission types for the assign grade item.
            // Assignments may have NO submission type - we need to know that.
            $gradeitem->submissiontypes = ($gradeitem->itemmodule === 'assign') ?
                helper::get_assign_submission_plugins($gradeitem->cmid) : 0;

            // All good - now get and store the feedback record.
            // TurnitinToolTwo special treatment as one grading item may have several parts.
            if ($gradeitem->itemmodule == 'turnitintooltwo') {
                self::get_user_turnitin_records($course, $gradeitem, $userid, $assessmenttypes, $data, $courseobject);
            } else {
                if ($record = self::get_user_feedback_record($course, $userid, $gradeitem, $assessmenttypes)) {
                    $data->items[] = $record;
                    $courseobject->items[] = $record;
                }
            }
            $itemlist[] = $gradeitem->itemid;
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
     * Get the user feedback record for a grade item.
     *
     * @param stdClass $course
     * @param int $userid
     * @param stdClass $gradeitem
     * @param array $assessmenttypes
     * @return stdClass|bool
     */
    protected static function get_user_feedback_record($course, $userid, $gradeitem, $assessmenttypes): stdClass|bool {
        $gradeitem->partid = 0; // Only turnitintooltwo assessments may have parts.
        return self::compile_user_data($course, $userid, $gradeitem, $assessmenttypes);
    }

    /**
     * Get the parts of a turnitintooltwo grading item and list them as separate items.
     *
     * @param stdClass $course
     * @param stdClass $gradeitem
     * @param int $userid
     * @param array $assessmenttypes
     * @param stdClass $data
     * @param stdClass $courseobject
     * @return void
     * @throws dml_exception
     * @throws coding_exception
     */
    protected static function get_user_turnitin_records(
      $course,
      $gradeitem,
      $userid,
      $assessmenttypes,
      &$data,
      &$courseobject
    ): void {

        $tttparts = helper::get_turnitin_records($course->id);

        foreach ($tttparts[$gradeitem->itemid] as $tttpart) {
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

                if ($item = self::compile_user_data($course, $userid, $gradeitem, $assessmenttypes)) {
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
     * @param array $assessmenttypes
     * @return stdClass|bool
     */
    protected static function compile_user_data($course, $userid, $gradeitem, $assessmenttypes): stdClass|bool {

        // Append the assessment type information where available.
        helper::append_assessment_type_to_gradeitem($gradeitem, $assessmenttypes);

        // Exclude assessments of type DUMMY.
        if (isset($gradeitem->assessmenttype) && ((int)$gradeitem->assessmenttype === assess_type::ASSESS_TYPE_DUMMY)) {
            return false;
        }

        $warningdays = get_config('report_feedback_tracker', 'warningdays');
        $warningperiod = $warningdays * DAYSECS; // Number of seconds in the warning period.
        $dateformat = get_config('report_feedback_tracker', 'dateformat');

        // If there is a manual feedback due date use it, otherwise calculate it from the submission due date where set.
        $feedbackduedate = helper::get_feedbackduedate($gradeitem);
        // Get the submission date if any.
        $submissiondate = helper::get_submissiondate($userid, $gradeitem);

        $data = new stdClass();
        $data->submissiondate = $submissiondate == 0 ? '--' : date($dateformat, $submissiondate);
        $data->submissionstatus = helper::get_submission_status($gradeitem, $submissiondate, $warningperiod);
        $data->courseid = $course->id;
        $data->coursename = $course->fullname;
        $data->cmid = $gradeitem->itemid;
        $data->assessment = helper::get_item_link($gradeitem);
        $data->assessmenttype = isset($gradeitem->assessmenttype) ? (int) $gradeitem->assessmenttype : null;
        $data->moduletypeicon = helper::get_module_type_icon($gradeitem);
        $data->module = helper::get_item_module($gradeitem);
        $data->formative = isset($data->assessmenttype) &&
            $data->assessmenttype === assess_type::ASSESS_TYPE_FORMATIVE ? true : false;
        $data->summative = isset($data->assessmenttype) &&
            $data->assessmenttype === assess_type::ASSESS_TYPE_SUMMATIVE ? true : false;
        $data->assesstypelabel = helper::get_assesstype_label($data->assessmenttype);
        $data->duedate = $gradeitem->duedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            date($dateformat, $gradeitem->duedate);
        $data->duedateraw = $gradeitem->duedate == 0 ? 9999999999 : $gradeitem->duedate;
        $data->feedbackduedate = $feedbackduedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            date($dateformat, $feedbackduedate);
        $data->feedbackduedateraw = $feedbackduedate == 0 ? 9999999999 : $feedbackduedate;
        $data->grade = ($gradeitem->finalgrade ?
            (int)$gradeitem->finalgrade . '/' . (int)$gradeitem->grademax : false);
        $data->student = $gradeitem->student;
        $data->grader = $gradeitem->grader;
        $data->feedbackdate = $gradeitem->feedbackdate ? $gradeitem->feedbackdate : $gradeitem->gfdate;
        $data->feedbackstatus = helper::get_feedback_status($gradeitem, $feedbackduedate, $submissiondate);
        $data->feedback = helper::get_feedback_badge($gradeitem, $feedbackduedate, $submissiondate);
        $data->method = $gradeitem->method;
        $data->responsibility = html_writer::div($gradeitem->responsibility);
        $data->generalfeedback = helper::get_generalfeedback($gradeitem);
        $data->gfurl = $gradeitem->gfurl;
        $data->contact = $gradeitem->responsibility;
        $data->additionaldata = $data->generalfeedback || $data->method || $data->contact;
        $data->isdummy = isset($data->assessmenttype) && $data->assessmenttype == assess_type::ASSESS_TYPE_DUMMY;

        return $data;
    }

}
