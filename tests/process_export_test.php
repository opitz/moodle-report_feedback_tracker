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
 * PHPUnit process export test
 *
 * @package    report_feedback_tracker
 * @category   test
 * @copyright  2026 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_feedback_tracker
 */

namespace report_feedback_tracker;

use advanced_testcase;
use report_feedback_tracker\task\process_export;
use stdClass;

/**
 * Unit test for the process to export data
 *
 * @covers \report_feedback_tracker\task\process_export
 */
final class process_export_test extends advanced_testcase {
    /**
     * Setup
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Test that execute initialises the course properly
     *
     * @covers \process_export
     * @return void
     */
    public function test_execute_initialises_course_property(): void {
        // Minimal course setup.
        $testcourse = $this->getDataGenerator()->create_course();

        // Configure export path.
        $exportpath = make_temp_directory('rft_init_test');
        set_config('export_path', $exportpath, 'report_feedback_tracker');

        // Create task with valid custom data.
        $year = 2024;
        $task = new process_export();
        $task->set_custom_data((object)[
            'courseid' => $testcourse->id,
            'academicyear' => $year,
        ]);

        // If $this->exportcourse is not initialised, execute() will fail.
        $task->execute();

        // If we get here, $this->exportcourse was initialised correctly.
        $summative = $exportpath . "/feedback_tracker_report_{$year}_{$testcourse->id}_summative.json";
        $formative = $exportpath . "/feedback_tracker_report_{$year}_{$testcourse->id}_formative.json";
        $this->assertFileExists($summative);
        $this->assertFileExists($formative);
    }

    /**
     * Test that custom feedback release date is exported.
     *
     * @return void
     */
    public function test_custom_feedback_release_date_is_used(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();

        // Create course and user.
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        // Create assignment module.
        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => time() - DAYSECS,
        ]);

        rebuild_course_cache($course->id, true);

        // Get grade item.
        $gradeitem = $DB->get_record('grade_items', [
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
        ], '*', MUST_EXIST);

        // Create a grade for user.
        $grade = new stdClass();
        $grade->itemid = $gradeitem->id;
        $grade->userid = $student->id;
        $grade->finalgrade = 75;
        $grade->hidden = 0;
        $grade->timemodified = time() - 3600;
        $DB->insert_record('grade_grades', $grade);

        // Insert custom feedback release date.
        $gfdate = time() - 1800;

        $rft = new stdClass();
        $rft->gradeitem = $gradeitem->id;
        $rft->gfdate = $gfdate;
        $DB->insert_record('report_feedback_tracker', $rft);

        // Build submission and coursemodule.
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $submission = (object)[
            'id' => 1,
            'userid' => $student->id,
            'submissiondatetime' => time() - 7200,
        ];

        $coursemodule = (object)[
            'id' => $cm->id,
            'instance' => $assign->id,
            'modname' => 'assign',
            'duedatetime' => $assign->duedate,
            'assessname' => $assign->name,
            'assesstype' => 'Summative',
        ];

        // Run the task.
        $task = new \report_feedback_tracker\task\process_export();
        $task->phpu_set_courseid($course->id);
        $record = $task->process_submission($submission, $coursemodule);

        // Check results. Custom feedback release date should be returned.
        $this->assertEquals($gfdate, $record->feedbackdatetime);
        $this->assertEquals('marked', $record->marked);
        $this->assertEquals(1, $record->releasedintime);
    }

    /**
     * Test exporting custom feedback due date correctly.
     *
     * @return void
     */
    public function test_custom_feedback_due_date_is_used_in_export(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();

        // Create course and student.
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        // Create assignment module.
        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'duedate' => time() + DAYSECS,
        ]);

        rebuild_course_cache($course->id, true);

        // Get course module and grade item.
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $gradeitem = $DB->get_record('grade_items', [
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
        ], '*', MUST_EXIST);

        // Insert grade for a student.
        $grade = new stdClass();
        $grade->itemid = $gradeitem->id;
        $grade->userid = $student->id;
        $grade->finalgrade = 75;
        $grade->hidden = 0;
        $grade->timemodified = time();
        $DB->insert_record('grade_grades', $grade);

        // Insert a custom feedback due date.
        $customduedate = time() + (10 * DAYSECS);

        $rft = new stdClass();
        $rft->gradeitem = $gradeitem->id;
        $rft->feedbackduedate = $customduedate;
        $DB->insert_record('report_feedback_tracker', $rft);

        // Fake submission and coursemodule objects.
        $submission = (object)[
            'id' => 1,
            'userid' => $student->id,
            'submissiondatetime' => time(),
        ];

        $coursemodule = (object)[
            'id' => $cm->id,
            'instance' => $assign->id,
            'modname' => 'assign',
            'duedatetime' => $assign->duedate,
            'assessname' => $assign->name,
            'assesstype' => get_string('summative', 'local_assess_type'),
        ];

        // Execute task.
        $task = new \report_feedback_tracker\task\process_export();
        $task->phpu_set_courseid($course->id);
        $task->set_custom_data((object)['academicyear' => 2025]);

        $record = $task->process_submission($submission, $coursemodule);

        // Check results.
        // Raw timestamp overridden.
        $this->assertEquals($customduedate, $record->feedbackduedatetime);

        // Formatted date also correct.
        $this->assertEquals(
            date('Y-m-d H:i:s', $customduedate),
            $record->feedbackduedate
        );
    }
}
