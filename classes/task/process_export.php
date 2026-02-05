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

namespace report_feedback_tracker\task;

use core\exception\moodle_exception;
use local_assess_type\assess_type;
use moodle_recordset;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;
use stdClass;

/**
 * Process the export of data for a course.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards Catalyst IT {@link http://www.catalyst-eu.net/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Conn Warwicker <conn.warwicker@catalyst-eu.net>
 */
class process_export extends \core\task\adhoc_task {
    /**
     * @var stdClass $course The course object being referenced
     */
    protected stdClass $course;

    /**
     * @var array<int> $counters Array of counters
     */
    protected array $counters = [];

    /**
     * Setter for $customdata.
     * @param mixed $customdata (anything that can be handled by json_encode)
     */
    public function set_custom_data($customdata) {
        parent::set_custom_data($customdata);

        $customdata = $this->get_custom_data();
        if (isset($customdata->courseid)) {
            $this->course = get_course($customdata->courseid);
        }
    }

    /**
     * Execute the adhoc task
     * @return void
     */
    public function execute(): void {
        $path = get_config('report_feedback_tracker', 'export_path') ?: make_temp_directory('report_feedback_tracker');

        // Make the files to use for this course and year.
        $filenameformative = "feedback_tracker_report_{$this->get_custom_data()->academicyear}_{$this->course->id}_formative.json";
        $filenamesummative = "feedback_tracker_report_{$this->get_custom_data()->academicyear}_{$this->course->id}_summative.json";
        $formativefile = helper::make_and_open_file($path . '/' . $filenameformative);
        $summativefile = helper::make_and_open_file($path . '/' . $filenamesummative);

        $this->counters['formative'] = 0;
        $this->counters['summative'] = 0;

        $coursemodules = $this->get_course_modules();
        mtrace('Found course modules for course ' . $this->course->id);

        foreach ($coursemodules as $coursemodule) {
            $submissions = admin::get_module_submissions($coursemodule);
            mtrace('Found ' . count($submissions) . ' submissions for course module ' . $coursemodule->id);
            foreach ($submissions as $submission) {
                $record = $this->process_submission($submission, $coursemodule);

                // JSON encode and write immediately.
                if ($record->assessmenttype === get_string('summative', 'local_assess_type')) {
                    helper::write_json_record($summativefile, json_encode($record), $this->counters['summative']);
                    $this->counters['summative']++;
                } else {
                    helper::write_json_record($formativefile, json_encode($record), $this->counters['formative']);
                    $this->counters['formative']++;
                }
            }
        }

        $formativefilepath = stream_get_meta_data($formativefile)['uri'];
        $summativefilepath = stream_get_meta_data($summativefile)['uri'];
        helper::close_file($formativefile);
        helper::close_file($summativefile);

        mtrace('Finished processing course ' . $this->course->id . ' for year ' . $this->get_custom_data()->academicyear);
        mtrace('Formative assessment file: ' . $formativefilepath);
        mtrace('Summative assessment file: ' . $summativefilepath);
    }

    /**
     * Process a submission and write it to the relevant file.
     * @param stdClass $submission
     * @param stdClass $coursemodule
     * @return void
     */
    public function process_submission(stdClass $submission, stdClass $coursemodule): stdClass {
        // Build record.
        $record = new stdClass();
        $record->submissionid = $submission->id;
        $record->duedatetime = $coursemodule->duedatetime;
        $record->submissionuserid = $submission->userid;
        $record->submissiongroupid = isset($submission->groupid) ? $submission->groupid : 0;
        $record->submissiondatetime = $submission->submissiondatetime;
        $record->cmid = $coursemodule->id;
        $record->cminstance = $coursemodule->instance;
        $record->courseid = $this->course->id;
        $record->categoryid = $this->course->category;
        $record->assessmentname = $coursemodule->assessname;
        $record->academicyear = $this->get_custom_data()->academicyear ?? '';
        $record->coursename = $this->course->fullname;
        $record->assessmentmod = $coursemodule->modname;
        $record->assessmenttype = $coursemodule->assesstype;

        // Add turnitin part data.
        if ($coursemodule->modname === 'turnitintooltwo') {
            $tttparts = helper::get_turnitin_parts($coursemodule->instance);
            foreach ($tttparts as $tttpart) {
                // Make a clone of the record and fill in the part details.
                $record = clone $record;
                $record->assessmentname .= ' ' . $tttpart->partname;
                $record->duedatetime = $tttpart->dtdue;
            }
        }

        self::amend_record_data($record);

        return $record;
    }

    /**
     * Get all assessment-type course modules for the given course.
     * @return moodle_recordset
     */
    public function get_course_modules(): moodle_recordset {

        global $CFG, $DB;

        $formative = get_string('formative', 'local_assess_type');
        $summative = get_string('summative', 'local_assess_type');

        // Get the summative and formative course modules.
        $sql = "SELECT
            cm.*,
            mo.name AS modname,
            CASE
                WHEN at.type = " . assess_type::ASSESS_TYPE_FORMATIVE . " THEN '" . $formative . "'
                WHEN at.type = " . assess_type::ASSESS_TYPE_SUMMATIVE . " THEN '" . $summative . "'
            END AS assesstype,
            CASE
                WHEN mo.name = 'assign' THEN amod.name
                WHEN mo.name = 'coursework' THEN cmod.name
                WHEN mo.name = 'lesson' THEN lmod.name
                WHEN mo.name = 'quiz' THEN qmod.name
                WHEN mo.name = 'turnitintooltwo' THEN tmod.name
                WHEN mo.name = 'workshop' THEN wmod.name
                WHEN mo.name = 'lti' THEN ltimod.name
                ELSE ''
            END AS assessname,
            CASE
                WHEN mo.name = 'assign' THEN amod.duedate
                WHEN mo.name = 'coursework' THEN cmod.deadline
                WHEN mo.name = 'lesson' THEN lmod.deadline
                WHEN mo.name = 'quiz' THEN qmod.timeclose
                WHEN mo.name = 'turnitintooltwo' THEN 0
                WHEN mo.name = 'workshop' THEN wmod.submissionend
                WHEN mo.name = 'lti' THEN rftlti.enddatetime
                ELSE 0
            END AS duedatetime
            FROM {course_modules} cm
            JOIN {local_assess_type} at ON at.cmid = cm.id AND at.type IN (0, 1)
            JOIN {modules} mo ON mo.id = cm.module
            LEFT JOIN {assign} amod ON mo.name = 'assign' AND amod.id = cm.instance
            LEFT JOIN {lesson} lmod ON mo.name = 'lesson' AND lmod.id = cm.instance
            LEFT JOIN {quiz} qmod ON mo.name = 'quiz' AND qmod.id = cm.instance
            LEFT JOIN {workshop} wmod ON mo.name = 'workshop' AND wmod.id = cm.instance
            LEFT JOIN {lti} ltimod ON mo.name = 'lti' AND ltimod.id = cm.instance
            LEFT JOIN {report_feedback_tracker_lti} rftlti ON mo.name = 'lti' AND rftlti.instanceid = cm.instance ";

        $pluginmanager = \core_plugin_manager::instance();

        // Check if Coursework is installed.
        if ($pluginmanager->get_plugin_info('mod_coursework')) {
            $sql .= "LEFT JOIN {coursework} cmod ON mo.name = 'coursework' AND cmod.id = cm.instance ";
        }
        // Check if TurnitinToolTwo is installed.
        if ($pluginmanager->get_plugin_info('mod_turnitintooltwo')) {
            $sql .= "LEFT JOIN {turnitintooltwo} tmod ON mo.name = 'turnitintooltwo' AND tmod.id = cm.instance ";
        }

        $sql .= "WHERE cm.course = :courseid";

        $params = ['courseid' => $this->course->id];
        return $DB->get_recordset_sql($sql, $params);
    }

    /**
     * Amending various data for the export record.
     * @param stdClass $record Record of data to amend
     * @return void
     */
    private static function amend_record_data(stdClass $record): void {
        // Set user specific due dates.
        self::set_duedates($record);

        // Set categories, faculties and departments.
        self::set_categories_faculties_departments($record);

        // Get grade item, marking and custom dates where available.
        self::set_grading_data($record);

        // Convert UNIX timestamps.
        self::convert_timestamps($record);

        // Add turnaround days.
        $record->turnarounddays = self::compute_turnaround_days($record);
    }

    /**
     * Convert UNIX timestamps.
     *
     * @param stdClass $record
     * @return void
     */
    private static function convert_timestamps(stdClass $record): void {
        $datetimeformat = 'Y-m-d H:i:s';
        $record->duedate = date($datetimeformat, $record->duedatetime);
        $record->userduedate = date($datetimeformat, $record->userduedatetime);
        $record->submissiondate = date($datetimeformat, $record->submissiondatetime);
        $record->feedbackduedate = date($datetimeformat, $record->feedbackduedatetime);
        $record->feedbackdate = date($datetimeformat, $record->feedbackdatetime);
    }

    /**
     * Return the number of days between submission and feedback release.
     *
     * @param stdClass $record
     * @return int
     */
    private static function compute_turnaround_days(stdClass $record): int {
        // If there is no user due date, there are no turnaround days.
        if (!$record->userduedatetime) {
            return 0;
        }

        // If feedback was given, return the days between user due date and feedback date.
        // If no feedback was (yet) given, return the days between user due date and today.
        $feedbackdatetime = $record->feedbackdatetime ?: time();
        // Count the user due date regardless of time of day.
        $userduedatetime = strtotime(date('Y-m-d', $record->userduedatetime));
        // Count the feedback date regardless of time of day.
        $feedbackdatetime = strtotime(date('Y-m-d', $feedbackdatetime));

        $elapseddays = intdiv($feedbackdatetime - $userduedatetime, DAYSECS);

        // Get the number of non-working days.
        $closuredays = helper::get_closuredays();

        for ($i = $userduedatetime; $i <= $feedbackdatetime; $i += DAYSECS) {
            // Check if the date is a weekend.
            $weekday = date('N', $i);

            // Don't count day if it's a weekend day (6 or 7) or a closure date.
            if ($weekday > 5 || in_array(date('Y-m-d', $i), $closuredays)) {
                $elapseddays--;
            }
        }

        return $elapseddays;
    }

    /**
     * Set a user specific submission due date and related feedback due date.
     *
     * @param stdClass $record
     * @return void
     */
    private static function set_duedates($record): void {
        $record->userduedatetime = self::get_duedate(
            $record->courseid,
            $record->assessmentmod,
            $record->cminstance,
            $record->duedatetime,
            $record->submissionuserid
        );
        $record->userextension = $record->duedatetime != $record->userduedatetime;
        $record->feedbackduedatetime = helper::get_feedbackduedate($record, $record->userduedatetime);
    }

    /**
     * Get the due date for a user including optional extensions and/or overrides.
     *
     * @param int $courseid
     * @param string $moduletype
     * @param int $instance
     * @param int $duedate
     * @param int $userid
     * @return false|int|mixed
     */
    private static function get_duedate(int $courseid, string $moduletype, int $instance, int $duedate, int $userid) {
        global $DB;

        switch ($moduletype) {
            case 'assign':
                // Get individual override where available.
                $params = ['assignid' => $instance, 'userid' => $userid];
                $overridedate = $DB->get_field('assign_overrides', 'duedate', $params);

                $usergroups = groups_get_user_groups($courseid, $userid);

                // If there is no individual override check for a group override date.
                if (!$overridedate) {
                    $params = ['assignid' => $instance];
                    foreach ($usergroups[0] as $usergroupid) {
                        $params['groupid'] = $usergroupid;
                        $overrideduedate = $DB->get_field('assign_overrides', 'duedate', $params);

                        if ($overrideduedate > $overridedate) {
                            $overridedate = $overrideduedate;
                        }
                    }
                }

                // Get individual extension where available.
                $params = ['assignment' => $instance, 'userid' => $userid];
                $extensiondate = $DB->get_field('assign_user_flags', 'extensionduedate', $params);

                // Use the date that gives the most time to the student.
                if ($extensiondate > $overridedate) {
                    $overridedate = $extensiondate;
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
     * Set categories, faculties and departments.
     *
     * @param stdClass $record
     * @return void
     */
    private static function set_categories_faculties_departments($record): void {
        global $DB;

        $category = $DB->get_record('course_categories', ['id' => $record->categoryid]);
        $record->category = self::strip_square_brackets($category->name);

        // A course may have a department as parent.
        if ($category->parent > 0) {
            $department = $DB->get_record('course_categories', ['id' => $category->parent]);
            $record->department = self::strip_square_brackets($department->name);

            // A department may have a faculty as parent.
            if ($department->parent > 0) {
                $facultyname = $DB->get_field('course_categories', 'name', ['id' => $department->parent]);
                $record->faculty = self::strip_square_brackets($facultyname);
            }
        }
    }

    /**
     * Remove square brackets and everything that is in between from a string.
     *
     * @param string $string
     * @return string
     */
    private static function strip_square_brackets(string $string): string {
        return trim(preg_replace('/\[.*?\]/', '', $string));
    }

    /**
     * Set grading data for a submission record.
     *
     * @param stdClass $record a submission record
     * @return void
     */
    public static function set_grading_data(stdClass $record): void {

        // Feedback release.
        // If there is a custom feedback released date it will take precedence over an individual feedback date.
        if (isset($record->gfdate) && $record->gfdate) {
            $record->feedbackdatetime = $record->gfdate;
            $record->marked = "marked";
            // If there is no due date or the due date has not yet passed, feedback was released in time.
            if (!$record->feedbackduedatetime || $record->feedbackduedatetime >= $record->feedbackdatetime) {
                $record->releasestatus = 'in time';
                $record->releasedintime = 1;
            } else { // Otherwise the feedback is late.
                $record->releasestatus = 'late';
                $record->releasedintime = 0;
            }
        } else {
            $graderecord = self::get_graderecord($record->assessmentmod, $record->cminstance, $record->submissionuserid);

            // If there is no final grade or grade not (yet) released.
            if (
                !$graderecord ||
                is_null($graderecord->finalgrade) ||
                $graderecord->hidden === 1 ||
                $graderecord->hidden > time()
            ) {
                $record->feedbackdatetime = false;
                $record->marked = "unmarked";
                $record->releasedintime = 0;

                // If there is a submission date, no feedback and the feedback due date has passed,
                // then feedback is overdue.
                if (
                    $record->submissiondatetime && $record->feedbackduedatetime &&
                    $record->feedbackduedatetime < time()
                ) {
                    $record->releasestatus = 'overdue';
                } else { // No submission or no feedback due date or still within feedback period - in marking.
                    $record->releasestatus = 'in marking';
                }
            } else {
                $record->feedbackdatetime = $graderecord->gradesreleased;
                $record->marked = "marked";
                // If there is no due date or the due date has not yet passed, feedback was released in time.
                if (!$record->feedbackduedatetime || $record->feedbackduedatetime >= $record->feedbackdatetime) {
                    $record->releasestatus = 'in time';
                    $record->releasedintime = 1;
                } else { // Otherwise the feedback is late.
                    $record->releasestatus = 'late';
                    $record->releasedintime = 0;
                }
            }
        }
    }

    /**
     * Get grade item and marking.
     *
     * @param string $itemmodule
     * @param int $cmid
     * @param int $userid
     * @return mixed
     */
    private static function get_graderecord(string $itemmodule, int $cmid, int $userid) {
        global $DB;

        $sql = "SELECT
                    ROW_NUMBER() OVER (ORDER BY gg.id) AS uniqueid,
                    gg.finalgrade,
                    gg.hidden,
                    gg.timemodified,
                    gi.courseid,
                    gi.itemname,
                    gi.itemtype,
                    rft.partid,
                    rft.feedbackduedate,
                    rft.gfdate,
                    coalesce(rftlti.gradesreleased, gg.timemodified) as gradesreleased
                FROM {grade_items} gi
                JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                LEFT JOIN {report_feedback_tracker} rft ON rft.gradeitem = gi.id
                LEFT JOIN {report_feedback_tracker_lti} rftlti ON rftlti.instanceid = gi.iteminstance AND gi.itemmodule = 'lti'
                WHERE gi.itemmodule = :itemmodule AND gi.iteminstance = :iteminstance
                ";

        $params = [
            'itemmodule' => $itemmodule,
            'iteminstance' => $cmid,
            'userid' => $userid,
        ];

        // At workshop assessments may have more than one final grade: for submission and assessment.
        // Turnitin assessments may have several parts.
        // Curently this only picks the 1st grade record.
        // Todo: Support multiple records for turnitin and workshop.
        return $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);
    }
}
