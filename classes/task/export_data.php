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

/**
 * Export data.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace report_feedback_tracker\task;

use core\task\scheduled_task;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\helper;
use report_feedback_tracker\local\user;
use stdClass;

/**
 * Task to write data to a file.
 */
class export_data extends scheduled_task {
    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('data:export', 'report_feedback_tracker');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        // Get the data to export.
        $data = (array) self::get_export_data();

        // Convert data to JSON for writing.
        $output = json_encode($data, JSON_PRETTY_PRINT);

        // Export the output.
        $filename = 'feedback_tracker_report.json';

        // If a path is configured use it and log the outcome.
        if ($exportpath = get_config('report_feedback_tracker', 'export_path')) {
            $filepath = $exportpath  . '/' . $filename;

        } else { // Export to temp directory of moodledata and log the outcome.
            $filepath = make_temp_directory('report_feedback_tracker') . '/' . $filename;
        }

        // Write the data to the file.
        if (file_put_contents($filepath, $output)) {
            // Log the outcome.
            mtrace(get_string('data:export_log', 'report_feedback_tracker') . ": $filepath");

            mtrace(" ==> " . count($data) . " records exported.");

            // Trigger an event.
            $event = \report_feedback_tracker\event\data_export_completed::create([
                'context' => \context_system::instance(),
                'other' => ['greetings' => 'Live long and prosper!'], // Probably not needed...
            ]);
            $event->trigger();
        } else {
            // Log the outcome.
            mtrace("Failed to write data: $filepath");
        }
    }

    /**
     * Get the data to export - taking steps.
     *
     * @return array
     */
    public static function get_export_data(): array {
        global $DB;

        $records = [];

        $academicyear = get_config('report_feedback_tracker', 'export_academicyear') ?: helper::get_current_academic_year();
        $aystartdate = strtotime($academicyear . '-10-01');
        $ayenddate = strtotime(( (int)$academicyear + 1) . '-09-30');

        // NO limit in number of records unless specified in settings.
        $limit = get_config('report_feedback_tracker', 'export_limit') ?: 0;

        $counter = 0;

        // Get courses for academic year.
        $courses = $DB->get_records_select('course',
            '((startdate >= :start1 AND startdate <= :end1) OR (enddate >= :start2 AND enddate <= :end2)) AND id <> 1',
            ['start1' => $aystartdate, 'end1' => $ayenddate, 'start2' => $aystartdate, 'end2' => $ayenddate]
        );

        foreach ($courses as $course) {
            // Get the summative course modules.
            $sql = "SELECT
                    cm.*,
                    mo.name AS modname,
                    CASE
                        WHEN mo.name = 'assign' THEN amod.name
                        WHEN mo.name = 'lesson' THEN lmod.name
                        WHEN mo.name = 'quiz' THEN qmod.name
                        WHEN mo.name = 'turnitintooltwo' THEN tmod.name
                        WHEN mo.name = 'workshop' THEN wmod.name
                        ELSE ''
                    END AS assessname,
                    CASE
                        WHEN mo.name = 'assign' THEN amod.timemodified
                        WHEN mo.name = 'lesson' THEN lmod.deadline
                        WHEN mo.name = 'quiz' THEN qmod.timeclose
                        WHEN mo.name = 'turnitintooltwo' THEN 0
                        WHEN mo.name = 'workshop' THEN wmod.submissionend
                        ELSE 0
                    END AS duedatetime

                    FROM {course_modules} cm
                    JOIN {local_assess_type} at ON at.cmid = cm.id AND at.type = 1
                    JOIN {modules} mo ON mo.id = cm.module
                    LEFT JOIN {assign} amod ON (mo.name = 'assign' AND amod.id = cm.instance)
                    LEFT JOIN {lesson} lmod ON (mo.name = 'lesson' AND lmod.id = cm.instance)
                    LEFT JOIN {quiz} qmod ON (mo.name = 'quiz' AND qmod.id = cm.instance)
                    LEFT JOIN {turnitintooltwo} tmod ON (mo.name = 'turnitintooltwo' AND tmod.id = cm.instance)
                    LEFT JOIN {workshop} wmod ON (mo.name = 'workshop' AND wmod.id = cm.instance)

                    WHERE cm.course = :courseid";

            $params = ['courseid' => $course->id];
            $coursemodules = $DB->get_records_sql($sql, $params);

            // Get the submissions for the summative assessments.
            foreach ($coursemodules as $summativecm) {
                if ($submissions = admin::get_module_submissions($course->id, $summativecm->modname, $summativecm->instance)) {

                    $datetimeformat = 'Y-m-d H:i:s';
                    foreach ($submissions as $submission) {
                        $record = new stdClass();
                        $record->submissionid = $submission->id;
                        $record->duedatetime = $summativecm->duedatetime;
                        $record->submissionuserid = $submission->userid;
                        $record->submissiongroupid = $submission->groupid ?? 0;
                        $record->submissiondatetime = $submission->submissiondatetime;
                        $record->cmid = $summativecm->id;
                        $record->cminstance = $summativecm->instance;
                        $record->courseid = $course->id;
                        $record->categoryid = $course->category;
                        $record->assessmentname = $summativecm->assessname;
                        $record->academicyear = $academicyear;
                        $record->coursename = $course->fullname;
                        $record->assessmentmod = $summativecm->modname;

                        // Set user specific due dates.
                        self::set_duedates($record);

                        // Set categories, faculties and departments.
                        self::set_categories_faculties_departments($record);

                        // Get grade item, marking and custom dates where available.
                        self::set_grading_data($record);

                        // Convert UNIX timestamps.
                        self::convert_timestamps($datetimeformat, $record);

                        $records[] = $record;

                        // If a limit is set stop when it has been reached.
                        if ($limit && ++$counter >= $limit) {
                            return $records;
                        }
                    }
                }
            }
        }
        return $records;
    }

    /**
     * Convert UNIX timestamps.
     *
     * @param string $datetimeformat
     * @param stdClass $record
     * @return void
     */
    private static function convert_timestamps(string $datetimeformat, stdClass $record): void {
        $record->duedate = date($datetimeformat, $record->duedatetime);
        $record->userduedate = date($datetimeformat, $record->userduedatetime);
        $record->submissiondate = date($datetimeformat, $record->submissiondatetime );
        $record->feedbackduedate = date($datetimeformat, $record->feedbackduedatetime);
        $record->feedbackdate = date($datetimeformat, $record->feedbackdatetime);
    }

    /**
     * Set a user specific submission due date and related feedback due date.
     *
     * @param stdClass $record
     * @return void
     */
    public static function set_duedates($record) {
        $record->userduedatetime = user::get_duedate(
            $record->courseid,
            $record->assessmentmod,
            $record->cminstance,
            $record->duedatetime,
            $record->submissionuserid);
        $record->userextension = $record->duedatetime != $record->userduedatetime;
        $record->feedbackduedatetime = helper::get_feedbackduedate($record, $record->userduedatetime);
    }

    /**
     * Set categories, faculties and departments.
     *
     * @param stdClass $record
     * @return void
     */
    public static function set_categories_faculties_departments($record) {
        global $DB;

        $category = $DB->get_record('course_categories', ['id' => $record->categoryid]);
        $record->category = $category->name;

        // A course has a department as parent.
        if ($category->parent > 0) {
            $department = $DB->get_record('course_categories', ['id' => $category->parent]);
            $record->department = $department->name;

            // A department has a faculty as parent.
            if ($department->parent > 0) {
                $record->faculty = $DB->get_field('course_categories', 'name', ['id' => $department->parent]);
            }
        }
    }

    /**
     * Set grading data for a submission record.
     *
     * @param stdClass $record a submission record
     * @return void
     */
    public static function set_grading_data(stdClass $record): void {

        $graderecord = self::get_graderecord($record->assessmentmod, $record->cminstance, $record->submissionuserid);

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
            // If there is no final grade or grade not (yet) released.
            if (!isset($graderecord->finalgrade) || !$graderecord->finalgrade ||
                    $graderecord->hidden === 1 || $graderecord->hidden > time()) {
                $record->feedbackdatetime = false;

                if ($record->submissiondatetime && $record->feedbackduedatetime &&
                        $record->feedbackduedatetime < time()) {
                    // If there is a submission date, no feedback and the feedback due date has passed,
                    // then feedback is overdue.
                    $record->releasestatus = 'overdue';
                    $record->releasedintime = 0;
                    $record->marked = "unmarked";
                } else { // No submission or no feedback due date or still within feedback period - in marking.
                    $record->releasestatus = 'in marking';
                    $record->releasedintime = 0;
                }

            } else {
                $record->feedbackdatetime = $graderecord->timemodified;
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
                    rft.gfdate
                FROM {grade_items} gi
                JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                LEFT JOIN {report_feedback_tracker} rft ON rft.gradeitem = gi.id
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
