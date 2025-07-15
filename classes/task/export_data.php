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
        global $DB;

        $academicyear = get_config('report_feedback_tracker', 'export_academicyear') ?: helper::get_current_academic_year();
        $previousyear = $academicyear - 1;
        $academicyears = [$previousyear, $academicyear];

        foreach ($academicyears as $acyear) {
            $sql =
                "SELECT c.id, c.category, c.fullname
               FROM {customfield_data} cfd
               JOIN {context} ctx ON cfd.contextid = ctx.id AND ctx.contextlevel = :contextcourse
               JOIN {course} c ON c.id = ctx.instanceid
               JOIN {customfield_field} cff ON cfd.fieldid = cff.id
              WHERE cff.shortname = 'course_year' AND cfd.value = :academicyear";
            $params = ['contextcourse' => CONTEXT_COURSE, 'academicyear' => $acyear];

            $courses = $DB->get_records_sql($sql, $params);
            // NO limit in number of records unless specified in settings.
            $limit = get_config('report_feedback_tracker', 'export_limit') ?: 0;
            $counter = 0;
            $firstline = true;

            // Create file and filepath.
            $filename = "feedback_tracker_report_$acyear.json";

            // If a path is configured use it.
            if ($exportpath = get_config('report_feedback_tracker', 'export_path')) {
                $filepath = $exportpath  . '/' . $filename;

            } else { // Otherwise export to the moodledata temp directory.
                $filepath = make_temp_directory('report_feedback_tracker') . '/' . $filename;
            }

            // Open the file.
            $handle = fopen($filepath, 'w');
            if ($handle === false) {
                mtrace(get_string('data:open_file_error', 'report_feedback_tracker', $filepath));
                continue;
            }
            fwrite($handle, "[\n");

            foreach ($courses as $course) {
                $courseacademicyear = helper::get_academic_year($course->id);
                // Only export courses with an academic year matching the selected academic year.
                if (!$courseacademicyear || $courseacademicyear != $acyear) {
                    continue;
                }

                // Get the summative course modules.
                $sql = "SELECT
                    cm.*,
                    mo.name AS modname,
                    CASE
                        WHEN mo.name = 'assign' THEN amod.name
                        WHEN mo.name = 'coursework' THEN cmod.name
                        WHEN mo.name = 'lesson' THEN lmod.name
                        WHEN mo.name = 'quiz' THEN qmod.name
                        WHEN mo.name = 'turnitintooltwo' THEN tmod.name
                        WHEN mo.name = 'workshop' THEN wmod.name
                        ELSE ''
                    END AS assessname,
                    CASE
                        WHEN mo.name = 'assign' THEN amod.duedate
                        WHEN mo.name = 'coursework' THEN cmod.deadline
                        WHEN mo.name = 'lesson' THEN lmod.deadline
                        WHEN mo.name = 'quiz' THEN qmod.timeclose
                        WHEN mo.name = 'turnitintooltwo' THEN 0
                        WHEN mo.name = 'workshop' THEN wmod.submissionend
                        ELSE 0
                    END AS duedatetime

                    FROM {course_modules} cm
                    JOIN {local_assess_type} at ON at.cmid = cm.id AND at.type = 1
                    JOIN {modules} mo ON mo.id = cm.module
                    LEFT JOIN {assign} amod ON mo.name = 'assign' AND amod.id = cm.instance
                    LEFT JOIN {coursework} cmod ON mo.name = 'coursework' AND cmod.id = cm.instance
                    LEFT JOIN {lesson} lmod ON mo.name = 'lesson' AND lmod.id = cm.instance
                    LEFT JOIN {quiz} qmod ON mo.name = 'quiz' AND qmod.id = cm.instance
                    LEFT JOIN {turnitintooltwo} tmod ON mo.name = 'turnitintooltwo' AND tmod.id = cm.instance
                    LEFT JOIN {workshop} wmod ON mo.name = 'workshop' AND wmod.id = cm.instance

                    WHERE cm.course = :courseid";

                $params = ['courseid' => $course->id];
                $coursemodules = $DB->get_records_sql($sql, $params);

                // Get the submissions for the summative assessments.
                foreach ($coursemodules as $summativecm) {
                    $submissions = admin::get_module_submissions($summativecm);
                    foreach ($submissions as $submission) {
                        if ($firstline) {
                            $firstline = false;
                        } else {
                            fwrite($handle, ",\n"); // Add a new line.
                        }
                        // Build record.
                        $record = new stdClass();
                        $record->submissionid = $submission->id;
                        $record->duedatetime = $summativecm->duedatetime;
                        $record->submissionuserid = $submission->userid;
                        $record->submissiongroupid = isset($submission->groupid) ? $submission->groupid : 0;
                        $record->submissiondatetime = $submission->submissiondatetime;
                        $record->cmid = $summativecm->id;
                        $record->cminstance = $summativecm->instance;
                        $record->courseid = $course->id;
                        $record->categoryid = $course->category;
                        $record->assessmentname = $summativecm->assessname;
                        $record->academicyear = $courseacademicyear;
                        $record->coursename = $course->fullname;
                        $record->assessmentmod = $summativecm->modname;

                        // Add turnitin part data.
                        if ($summativecm->modname === 'turnitintooltwo') {
                            $tttparts = helper::get_turnitin_parts($summativecm->instance);
                            foreach ($tttparts as $tttpart) {
                                // Make a clone of the record and fill in the part details.
                                $tttrecord = clone $record;
                                $tttrecord->assessmentname .= ' ' . $tttpart->partname;
                                $tttrecord->duedatetime = $tttpart->dtdue;
                                self::amend_record_data($tttrecord);
                                // JSON encode and write immediately.
                                fwrite($handle, json_encode($tttrecord,
                                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                                $counter ++;
                            }
                        } else {
                            self::amend_record_data($record);
                            // JSON encode and write immediately.
                            fwrite($handle, json_encode($record,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                            $counter ++;
                        }

                        // If a limit is set break when it has been reached.
                        if ($limit && $counter >= $limit) {
                            fwrite($handle, "\n]");
                            mtrace(get_string('data:export_count', 'report_feedback_tracker',
                                (object) ['count' => $counter, 'acyear' => $acyear]));
                            fclose($handle);
                            break 3; // Break to the next academic year.
                        }
                    }
                }
            }
            fwrite($handle, "\n]");
            mtrace(get_string('data:export_count', 'report_feedback_tracker',
                (object) ['count' => $counter, 'acyear' => $acyear]));
            fclose($handle);
        }

        // Trigger an event.
        $event = \report_feedback_tracker\event\data_export_completed::create([
            'context' => \context_system::instance(),
        ]);
        $event->trigger();
    }

    /**
     * Amending various data for the export record.
     *
     * @param stdClass $record
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
    private static function set_duedates($record) {
        $record->userduedatetime = self::get_duedate(
            $record->courseid,
            $record->assessmentmod,
            $record->cminstance,
            $record->duedatetime,
            $record->submissionuserid);
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
    private static function set_categories_faculties_departments($record) {
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
    private static function set_grading_data(stdClass $record): void {

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
