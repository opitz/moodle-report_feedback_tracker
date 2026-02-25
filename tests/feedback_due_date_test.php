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
 * Unit tests for report_feedback_tracker site.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace report_feedback_tracker;

use advanced_testcase;
use core_course\customfield\course_handler;
use grade_item;
use report_feedback_tracker\local\helper;
use core_customfield\category_controller;
use core_customfield\field_controller;
use core_customfield\data_controller;
use report_feedback_tracker\local\module_helper_factory;
use report_feedback_tracker\local\student;

/**
 * Tests for feedback due date calculation.
 *
 * @package report_feedback_tracker
 * @category   test
 * @copyright  2025 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_feedback_tracker
 */
final class feedback_due_date_test extends advanced_testcase {
    /**
     * Set up the tests.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        $this->setAdminUser();
        $this->resetAfterTest();
    }

    /**
     * Test a feedback due date before academic year 2024-25. It should be 1 month after the given due date.
     *
     * @return void
     */
    public function test_before_2024(): void {
        // Set up course with a custom field and data for the academic year.
        $this->getDataGenerator()->create_custom_field_category(['name' => 'CLC']);
        $this->getDataGenerator()->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        $course = $this->getDataGenerator()->create_course([
            'customfields' => [
                ['shortname' => 'course_year', 'value' => '2023'],
            ],
        ]);

        $gradeitem = new grade_item();
        $gradeitem->courseid = $course->id;

        $duedate = strtotime("2023-11-17");
        $expecteddate = strtotime("2023-12-17"); // One month after the due date.
        $feedbackduedate = strtotime('midnight', helper::get_feedbackduedate($gradeitem, $duedate));
        $this->assertEquals($expecteddate, $feedbackduedate);
    }

    /**
     * Test a feedback due date from academic year 2024-25 on. It should be 20 working days after a given due date.
     *
     * @return void
     */
    public function test_from_2024(): void {
        // Set up course with a custom field and data for the academic year.
        $this->getDataGenerator()->create_custom_field_category(['name' => 'CLC']);
        $this->getDataGenerator()->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        $course = $this->getDataGenerator()->create_course([
            'customfields' => [
                ['shortname' => 'course_year', 'value' => '2025'],
            ],
        ]);

        $gradeitem = new \grade_item();
        $gradeitem->courseid = $course->id;

        $duedate = strtotime("2025-04-07");
        $expecteddate = strtotime("2025-05-08"); // 20 working days after the due date.
        $feedbackduedate = strtotime('midnight', helper::get_feedbackduedate($gradeitem, $duedate));

        $this->assertEquals($expecteddate, $feedbackduedate);
    }

    /**
     * Test correct feedback due date for student with due date override
     *
     * @return void
     */
    public function test_student_due_date_override(): void {
        global $DB;

        // Set up course with a custom field and data for the academic year.
        $this->getDataGenerator()->create_custom_field_category(['name' => 'CLC']);
        $this->getDataGenerator()->create_custom_field(['category' => 'CLC', 'shortname' => 'course_year']);

        $course = $this->getDataGenerator()->create_course([
            'customfields' => [
                ['shortname' => 'course_year', 'value' => '2025'],
            ],
        ]);

        $student = $this->getDataGenerator()->create_user();

        // Create a course module.
        $quiz = $this->getDataGenerator()->create_module(
            'quiz',
            [
                'course' => $course->id,
                'name' => 'Test quiz',
                'timeclose' => strtotime('2025-11-01 17:00'),
                ]
        );
        $cm = \cm_info::create(get_coursemodule_from_instance('quiz', $quiz->id));

        // Create a student override.
        $override = (object)[
            'quiz'      => $quiz->id,
            'userid'    => $student->id,
            'timeclose' => strtotime('2025-11-10 17:00'),
            'timelimit' => null,
            'attempts'  => null,
        ];
        $DB->insert_record('quiz_overrides', $override);

        // Create a grade item.
        $gradeitem = new \grade_item();
        $gradeitem->courseid = $course->id;
        $gradeitem->itemmodule = $cm;
        $gradeitem->iteminstance = $quiz->id;

        $studentduedate = module_helper_factory::create($cm)->get_user_duedate(
            $gradeitem,
            $student->id
        );

        $expecteddate = strtotime("2025-12-08"); // 20 working days after the student due date.
        $feedbackduedate = strtotime('midnight', helper::get_feedbackduedate($gradeitem, $studentduedate));
        $this->assertEquals($expecteddate, $feedbackduedate);
    }
}
