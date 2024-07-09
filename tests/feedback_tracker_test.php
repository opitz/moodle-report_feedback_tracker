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

namespace report_feedback_tracker;

use advanced_testcase;
use context_course;


/**
 * PHPUnit report_feedback_tracker tests
 *
 * @package    report_feedback_tracker
 * @category   test
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_feedback_tracker
 */
final class feedback_tracker_test extends advanced_testcase {

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../lib.php');
    }

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Test the user/student data.
     *
     * @covers \get_feedback_tracker_user_data
     * @return void
     * @throws \coding_exception
     */
    public function test_get_feedback_tracker_user_data(): void {

        // Create a course and prepare the page.
        $course = $this->getDataGenerator()->create_course();
        $page = new \moodle_page();
        $page->set_context(context_course::instance($course->id));
        $page->set_pagelayout('course');

        // Create users and enrol them.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');

        // Setup some dummy data.
        $this->setup_dummy_data($course, $teacher, $student1, $student2);

        // Get the user data.
        $userdata = get_feedback_tracker_user_data($student1->id, $course->id);

        $records = $userdata->records;
        $feedbackreleased = $records[0];
        $feedbackextended = $records[1];
        $feedbacklate = $records[2];
        $submissionlate = $records[3];

        $this->assertEquals($student1->username, $feedbackreleased->student, "Assert submission is by student 1");
        $this->assertTrue(strstr($feedbackreleased->submissionstatus, 'Submission in time') > 0,
            "Assert submission is in time");
        $this->assertEquals('80/100', $feedbackreleased->grade, "Assert grade is shown correctly");
        $this->assertEquals('Released', $feedbackreleased->feedbackstatus, "Assert feedback is released");

        $this->assertEquals('Feedback in extended period', $feedbackextended->feedbackstatus,
            "Assert feedback in extended period");
        $this->assertEquals('Late', $feedbacklate->feedbackstatus, "Assert late feedback");
        $this->assertTrue(strstr($submissionlate->submissionstatus, 'Submission was late') > 0,
            "Assert that submission was late");
    }

    /**
     * Setup some dummy grade data.
     *
     * @param \stdClass $course
     * @param \stdClass $teacher
     * @param \stdClass $student1
     * @param \stdClass $student2
     * @return void
     * @throws \coding_exception
     */
    private function setup_dummy_data($course, $teacher, $student1, $student2): void {
        global $CFG, $DB;

        // Create an array of modules and their grades.
        $dummymodules = [
            [
                'modulename' => 'assign',
                'name' => "Assign 1",
                'itemname' => "Grade assign item 1",
                'user' => $student1->id,
                'timesubmitted' => strtotime("-6 weeks", time()),
                'grade' => "80",
                'duedate' => strtotime("-3 weeks", time()),
                'timemodified' => strtotime("-1 week", time()),
            ],
            [
                'modulename' => 'assign',
                'name' => "Assign 2",
                'itemname' => "Grade assign item 2",
                'user' => $student1->id,
                'timesubmitted' => strtotime("-40 days", time()),
                'grade' => "69",
                'duedate' => strtotime("-35 days", time()),
                'timemodified' => strtotime("-16 days", time()),
            ],
            [
                'modulename' => 'assign',
                'name' => "Assign 3",
                'itemname' => "Grade assign item 3",
                'user' => $student1->id,
                'timesubmitted' => strtotime("-3 days", time()),
                'grade' => "70",
                'duedate' => strtotime("-40 days", time()),
                'timemodified' => strtotime("-6 days", time()),
            ],
            [
                'modulename' => 'assign',
                'name' => "Assign 4",
                'itemname' => "Grade assign item 4",
                'user' => $student1->id,
                'timesubmitted' => strtotime("-3 weeks", time()),
                'grade' => "71",
                'duedate' => strtotime("-6 weeks", time()),
                'timemodified' => strtotime("-6 days", time()),
            ],
        ];

        // Create modules, grade items and grades from the dummy data.
        foreach ($dummymodules as $dmodule) {
            // Create the module.
            // Create for turnitintooltwo only if a data generator is present.
            if ($dmodule['modulename'] == 'turnitintooltwo') {
                if (file_exists($CFG->dirroot . '/mod/turnitintooltwo/tests/generator/lib.php')) {
                    $module = $this->getDataGenerator()->create_module($dmodule['modulename'],
                        ['course' => $course->id, 'name' => $dmodule['name']]);
                } else {
                    continue;
                }
            } else {
                if (isset($dmodule['duedate'])) {
                    $module = $this->getDataGenerator()->create_module($dmodule['modulename'],
                        [
                            'course' => $course->id,
                            'name' => $dmodule['name'],
                            'duedate' => $dmodule['duedate'],
                        ]);
                } else {
                    $module = $this->getDataGenerator()->create_module($dmodule['modulename'],
                        [
                            'course' => $course->id,
                            'name' => $dmodule['name'],
                        ]);
                }
            }

            if ($dmodule['modulename'] == 'assign' && $dmodule['timemodified'] > 0) {
                $coursemodule = get_coursemodule_from_instance($dmodule['modulename'], $module->id, $course->id);
                // Get the grade item.
                $gradeitem = $DB->get_record('grade_items', [
                    'courseid' => $course->id,
                    'iteminstance' => $coursemodule->instance,
                    'itemmodule' => $coursemodule->modname,
                ]);

                // Create the grade_grade.
                $gradegradedata = [
                    'itemid' => $gradeitem->id,
                    'userid' => $dmodule['user'],
                    'teamsubmission' => false,
                    'attemptnumber' => 0,
                    'grade' => $dmodule['grade'],
                    'usermodified' => $teacher->id,
                    'timemodified' => $dmodule['timemodified'],
                ];
                $this->getDataGenerator()->create_grade_grade($gradegradedata);
            }
            // Update the submission record.
            $this->update_submission($coursemodule, $dmodule['user'], $dmodule['timesubmitted']);

        }
    }

    /**
     * Update a submission for a student and a course module.
     *
     * @param \stdClass $module
     * @param int $studentid
     * @param int $submissiondate
     * @return void
     * @throws \dml_exception
     */
    private function update_submission($module, $studentid, $submissiondate) {
        global $DB;

        switch ($module->modname) {
            case 'assign':
                if ($submissiondata = $DB->get_record('assign_submission', [
                    'assignment' => $module->instance,
                    'userid' => $studentid,
                ])) {
                    $submissiondata->timemodified = $submissiondate;
                    $submissiondata->status = 'submitted';

                    // Update submission data.
                    $DB->update_record('assign_submission', $submissiondata);
                }
                break;

            case 'quiz':
                // Coming...
                break;

        }
    }

}

