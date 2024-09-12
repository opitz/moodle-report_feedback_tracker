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
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function get_feedback_tracker_user_data($userid, $courseid = 0): stdClass {
        $data = new stdClass();
        $data->records = [];
        $data->courses = [];

        // Check if we want to show a module header.
        $data->modheader = get_config('report_feedback_tracker', 'modheader');

        // If a course ID is given return data for that course only
        // otherwise return data for all courses a user is enrolled in.
        if ($courseid) {
            $course = get_course($courseid);
            try {
                self::get_user_course_gradings($course, $userid, $data);
            } catch (\coding_exception $e) {
                throw($e);
            }
        } else {
            $enrolledcourses = enrol_get_users_courses($userid);
            foreach ($enrolledcourses as $course) {
                self::get_user_course_gradings($course, $userid, $data);
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
        rft.partname,
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

        $summativeids = helper::get_summative_ids($course->id);

        $courseobject = new stdClass();
        $courseobject->courseid = $course->id;
        $courseobject->url = helper::get_course_url($course->id);
        $courseobject->shortname = $course->shortname;
        $courseobject->fullname = $course->fullname;
        $courseobject->academicyear = helper::get_academic_year($course->id);
        $courseobject->image = \core_course\external\course_summary_exporter::get_course_image($course);
        $courseobject->records = [];
        $itemlist = [];
        foreach ($gradeitems as $gradeitem) {
            // Check if the gradeitem module is supported
            // and make sure only one (turnitintooltwo) assessment record is listed even if there are multiple parts.
            if (!helper::module_is_supported($gradeitem) || in_array($gradeitem->itemid, $itemlist)) {
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
            if ($extension = helper::get_duedate_extension($gradeitem, $userid)) {
                $gradeitem->duedate = $extension;
            }
            // TurnitinToolTwo special treatment as one grading item may have several parts.
            if ($gradeitem->itemmodule == 'turnitintooltwo') {
                self::get_user_turnitin_records($course, $gradeitem, $userid, $summativeids, $data, $courseobject);
            } else {
                $record = self::get_user_feedback_record($course, $userid, $gradeitem, $summativeids);
                $data->records[] = $record;
                $courseobject->records[] = $record;
            }
            $itemlist[] = $gradeitem->itemid;
        }

        // Sort the courseobject records by due date.
        if (is_array($courseobject->records)) {
            usort($courseobject->records, function($a, $b) {
                return strcmp($a->duedateraw, $b->duedateraw);
            });
        }

        $data->courses[] = $courseobject;

        // Get the options for academic years.
        self::get_user_academic_years($data);
    }
    /**
     * Get the academic years a user is or has been enrolled into.
     *
     * @param stdClass $data
     * @return void
     */
    protected static function get_user_academic_years(&$data): void {
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
                return $b->value <=> $a->value; // For descending order.
            });
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
     * @throws coding_exception
     */
    protected static function get_user_feedback_record($course, $userid, $gradeitem, $summativeids): stdClass {
        $gradeitem->partname = null; // Only turnitintooltwo assessments may have parts.

        return self::compile_user_record($course, $userid, $gradeitem, $summativeids);
    }

    /**
     * Get the options for filtering the user table.
     *
     * @param stdClass $data
     * @return void
     */
    protected static function get_user_filter_options(&$data): void {
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
                return $b->value <=> $a->value; // For descending order.
            });
        }
    }

    /**
     * Show the general feedback and the gf URL to students.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    public static function get_user_generalfeedback($gradeitem): string {

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
     * Show a summative text for a summative grade item in students/users report.
     *
     * @param stdClass $gradeitem
     * @param array $summativeids
     * @return bool
     */
    public static function get_user_summative($gradeitem, $summativeids): bool {
        return $gradeitem->summative || array_key_exists($gradeitem->itemid, $summativeids);
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
     * @throws coding_exception
     */
    protected static function get_user_turnitin_records($course, $gradeitem, $userid, $summativeids, &$data, &$courseobject): void {
        // Get the parts.
        $tttparts = helper::get_tttparts($gradeitem);

        // Make each visible part a record and store it in the data.
        foreach ($tttparts as $tttpart) {
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
                $gradeitem->partname = $tttpart->partname;

                $record = self::compile_user_record($course, $userid, $gradeitem, $summativeids);
                $data->records[] = $record;
                $courseobject->records[] = $record;
            }
        }
    }

    /**
     * Compile the table record for a student / user.
     *
     * @param stdClass $course
     * @param int $userid
     * @param stdClass $gradeitem
     * @param array $summativeids
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function compile_user_record($course, $userid, $gradeitem, $summativeids): stdClass {

        $warningdays = get_config('report_feedback_tracker', 'warningdays');
        $warningperiod = $warningdays * DAYSECS; // Number of seconds in the warning period.
        $dateformat = get_config('report_feedback_tracker', 'dateformat');

        // If there is a manual feedback due date use it, otherwise calculate it from the submission due date where set.
        $feedbackduedate = helper::get_feedbackduedate($gradeitem);
        // Get the submission date if any.
        $submissiondate = helper::get_submissiondate($userid, $gradeitem);

        $record = new stdClass();
        $record->submissiondate = $submissiondate == 0 ? '--' : date($dateformat, $submissiondate);
        $record->submissionstatus = helper::get_submission_status($submissiondate, $gradeitem->duedate, $warningperiod);
        $record->course = $course->fullname;
        $record->courseid = $course->id;
        $record->coursename = $course->fullname;
        $record->academicyear = helper::get_academic_year($gradeitem->courseid);
        $record->assessment = helper::get_item_link($gradeitem);
        $record->type = helper::get_item_type($gradeitem);
        $record->module = helper::get_item_module($gradeitem);
        $record->summative = self::get_user_summative($gradeitem, $summativeids);
        $record->duedate = $gradeitem->duedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            date($dateformat, $gradeitem->duedate);
        $record->duedateraw = $gradeitem->duedate == 0 ? 9999999999 : $gradeitem->duedate;
        $record->feedbackduedate = $feedbackduedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            date($dateformat, $feedbackduedate);
        $record->feedbackduedateraw = $feedbackduedate == 0 ? 9999999999 : $feedbackduedate;
        $record->grade = ($gradeitem->finalgrade ?
            (int)$gradeitem->finalgrade . '/' . (int)$gradeitem->grademax : false);
        $record->student = $gradeitem->student;
        $record->grader = $gradeitem->grader;
        $record->feedbackdate = $gradeitem->feedbackdate ? $gradeitem->feedbackdate : $gradeitem->gfdate;
        $record->feedbackstatus = helper::get_feedback_status($gradeitem, $feedbackduedate, $submissiondate);
        $record->feedback = helper::get_feedback_badge($gradeitem, $feedbackduedate, $submissiondate);
        $record->method = $gradeitem->method;
        $record->responsibility = html_writer::div($gradeitem->responsibility);
        $record->generalfeedback = self::get_user_generalfeedback($gradeitem);
        $record->gfurl = $gradeitem->gfurl;
        $record->contact = $gradeitem->responsibility;
        $record->additionaldata = $record->generalfeedback || $record->method || $record->contact;

        return $record;
    }

}
