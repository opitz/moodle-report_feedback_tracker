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
 * This file contains the admin functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin {
    /**
     * Get the Feedback tracker data for all enrolled users of a given course.
     *
     * @param int $courseid
     * @return stdClass
     * @throws \dml_exception
     */
    public static function get_feedback_tracker_admin_data($courseid): stdClass {
        global $OUTPUT, $PAGE;

        $data = new stdClass();
        $data->records = [];

        // Get the students of the course.
        $sdata = new stdClass();
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context);
        $sdata->students = [];
        foreach ($users as $user) {
            // Check if the user has no managerial or supervising capabilities (e.g. is a student).
            if (!has_capability('gradereport/grader:view', $context, $user) &&
                !has_capability('moodle/course:manageactivities', $context, $user) &&
                !has_capability('enrol/category:synchronised', $context, $user) &&
                !has_capability('moodle/course:view', $context, $user)
            ) {
                $sdata->students[] = $user;
            } else { // If a user has a managerial or supervising role check if there is (also) a student role.
                $roles = get_user_roles($context, $user->id, true);
                foreach ($roles as $role) {
                    if (strstr($role->shortname, 'student')) {
                        $sdata->students[] = $user;
                        break;
                    }
                }
            }
        }

        // Render the drop down menu for switching into student view.
        $data->studentdd = $OUTPUT->render_from_template('report_feedback_tracker/studentdropdown', $sdata);

        // Check if the user is in edit mode.
        $data->editmode = $PAGE->user_is_editing();

        $course = get_course($courseid);
        // Get the gradings and append them to the data.
        self::get_admin_course_gradings($course, $data);

        return $data;
    }

    /**
     * Get the gradings for all users of a course and amend the data with the findings.
     *
     * @param stdClass $course
     * @param stdClass $data
     * @return void
     * @throws dml_exception
     */
    public static function get_admin_course_gradings($course, &$data): void {
        global $CFG, $DB, $PAGE;

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
    where gi.courseid = :courseid
";
        $params['courseid'] = $course->id;
        $gradeitems = $DB->get_records_sql($sql, $params);
        $assessmenttypes = helper::get_assessment_types($course->id);
        $modinfo = get_fast_modinfo($course->id);

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

            // All good - now get and store the feedback record.

            // TurnitinToolTwo special treatment as one grading item may have several parts.
            if ($gradeitem->itemmodule == 'turnitintooltwo') {
                self::get_admin_turnitin_records($course, $gradeitem, $assessmenttypes, $data);
            } else {
                if (!$gradeitem->hidden || $PAGE->user_is_editing()) {
                    $record = self::get_admin_feedback_record($course, $gradeitem, $assessmenttypes);
                    $data->records[] = $record;
                }
            }
            $itemlist[] = $gradeitem->itemid;
        }

        // Get the filter options where available.
        self::get_admin_filter_options($data);
    }

    /**
     * Get the admin feedback record for a grade item.
     *
     * @param stdClass $course
     * @param stdClass $gradeitem
     * @param array $assessmenttypes
     * @return stdClass
     * @throws dml_exception
     * @throws coding_exception
     */
    protected static function get_admin_feedback_record($course, $gradeitem, $assessmenttypes): stdClass {
        // Only turnitintooltwo assessments may have parts.
        $gradeitem->partid = $gradeitem->partid ? $gradeitem->partid : 0;
        return self::compile_admin_record($course, $gradeitem, $assessmenttypes);
    }

    /**
     * Get the parts of a turnitintooltwo grading item and list them as separate items.
     *
     * @param stdClass $course
     * @param stdClass $gradeitem
     * @param array $assessmenttypes
     * @param stdClass $data
     * @return void
     * @throws dml_exception
     * @throws coding_exception
     */
    protected static function get_admin_turnitin_records($course, $gradeitem, $assessmenttypes, &$data): void {
        global $PAGE;

        $tttparts = helper::get_turnitin_records($course->id);

        // Make each part a record and store it in the data.
        foreach ($tttparts[$gradeitem->itemid] as $tttpart) {
            if (!$tttpart->hidden || $PAGE->user_is_editing()) {
                // Reset the assessment type for each part.
                unset($gradeitem->assessmenttype);
                // Each ttt assessment part may have its own attributes.
                $gradeitem->summative = $tttpart->summative;
                $gradeitem->hidden = $tttpart->hidden;
                $gradeitem->feedbackduedate = $tttpart->feedbackduedate;
                $gradeitem->duedate = $tttpart->dtdue;
                $gradeitem->method = $tttpart->method;
                $gradeitem->responsibility = $tttpart->responsibility;
                $gradeitem->generalfeedback = $tttpart->generalfeedback;
                $gradeitem->gfurl = $tttpart->gfurl;
                $gradeitem->gfdate = $tttpart->gfdate;
                $gradeitem->partid = $tttpart->id;
                $gradeitem->partname = helper::get_partname($gradeitem->partid);

                $data->records[] = self::compile_admin_record($course, $gradeitem, $assessmenttypes);
            }
        }
    }

    /**
     * Compile a feedback record for a course admin.
     *
     * @param stdClass $course
     * @param stdClass $gradeitem
     * @param array $assessmenttypes
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function compile_admin_record($course, $gradeitem, $assessmenttypes): stdClass {

        // Set the assessment type of the grade item where available.
        helper::get_assessment_type($gradeitem, $assessmenttypes);

        $dateformat = get_config('report_feedback_tracker', 'dateformat');
        $feedbackduedate = helper::get_feedbackduedate($gradeitem);

        $record = new stdClass();
        $record->courseid = $course->id;
        $record->coursename = $course->fullname;
        $record->cmid = $gradeitem->itemid;
        $record->assessment = helper::get_item_link($gradeitem);
        $record->assessmenttype = isset($gradeitem->assessmenttype) ? (int) $gradeitem->assessmenttype : null;
        $record->locked = isset($gradeitem->locked) ? $gradeitem->locked : null;
        $record->moduletypeicon = helper::get_module_type_icon($gradeitem);
        $record->module = helper::get_item_module($gradeitem);
        $record->duedate = $gradeitem->duedate == 0 ?
            get_string('datenotset', 'report_feedback_tracker') :
            date($dateformat, $gradeitem->duedate);
        $record->duedateraw = $gradeitem->duedate == 0 ? 9999999999 : $gradeitem->duedate;
        $record->feedbackduedate = helper::render_feedbackduedate($gradeitem, $feedbackduedate);
        $record->feedbackduedateraw = $feedbackduedate == 0 ? 9999999999 : $feedbackduedate;
        $record->feedbacks = helper::get_feedbacks($gradeitem);
        $record->method = helper::get_feedback_method($gradeitem);
        $record->responsibility = helper::get_feedback_responsibility($gradeitem);
        $record->generalfeedback = self::get_admin_generalfeedback($gradeitem);
        $record->cohortfeedback = self::get_admin_cohortfeedback($gradeitem);
        $record->gfurl = $gradeitem->gfurl;
        $record->assesstypelabel = helper::get_assesstype_label($record->assessmenttype);
        $record->hidden = helper::get_hidden_state($gradeitem);
        $record->partid = $gradeitem->partid;
        $record->partname = isset($gradeitem->partname) ? $gradeitem->partname : '';

        // Get the assessment types with the current selection.
        $record->assesstypes = helper::get_assess_types(isset($record->assessmenttype) ? $record->assessmenttype : null);
        $record->notset = isset($record->assessmenttype) ? false : true;
        return $record;
    }

    /**
     * Show/edit the general feedback for a grade item.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    protected static function get_admin_generalfeedback($gradeitem): string {
        global $PAGE;

        $o = html_writer::start_div('generalfeedback align-items-center');
        if ($PAGE->user_is_editing()) {
            $o .= html_writer::span($gradeitem->generalfeedback, 'generalfeedbacktext',
                ['id' => 'generalfeedbacktext_' . $gradeitem->itemid]);

            $o .= ' ' . html_writer::tag('i', '',
                    [
                        'id' => html_writer::random_id('generalfeedback'),
                        'class' => 'icon fa fa-pencil fa-fw',
                        'data-cmid' => $gradeitem->itemid,
                        'data-partid' => $gradeitem->partid,
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
    protected static function get_admin_filter_options(&$data): void {
        // The filter options.
        $data->courseoptions = [];
        $data->typeoptions = [];
        $data->assesstypeoptions = [];
        $data->feedbackoptions = [];
        $data->methodoptions = [];

        foreach ($data->records as $record) {

            // Course options.
            if ($record->courseid) {
                $option = new stdClass();
                $option->key = $record->courseid;
                $option->value = $record->coursename;
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

            // Assessment type options.
            if (isset($record->assessmenttype)) {
                $option = new stdClass();
                $option->key = $record->assesstypelabel;
                $option->value = $record->assesstypelabel;
            } else {

                $option = new stdClass();
                $option->key = get_string('assesstype:notset', 'report_feedback_tracker');
                $option->value = get_string('assesstype:notset', 'report_feedback_tracker');
            }
            if (!in_array($option, $data->assesstypeoptions)) {
                $data->assesstypeoptions[] = $option;
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
    }

    /**
     * Edit / show the cohort feedback status for course admins.
     *
     * @param stdClass $gradeitem
     * @return string
     */
    protected static function get_admin_cohortfeedback($gradeitem): string {
        global $PAGE;

        if ($PAGE->user_is_editing()) {
            if ($gradeitem->gfdate) {
                return "<input
                data-action='report_feedback_tracker/cohort_checkbox'
                type='checkbox'
                class='form-check-input cohort_checkbox'
                data-cmid='$gradeitem->itemid'
                data-partid='$gradeitem->partid'
                checked='checked'
            >";
            } else {
                return "<input
                data-action='report_feedback_tracker/cohort_checkbox'
                type='checkbox'
                class='form-check-input cohort_checkbox'
                data-cmid='$gradeitem->itemid'
                data-partid='$gradeitem->partid'
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

}
