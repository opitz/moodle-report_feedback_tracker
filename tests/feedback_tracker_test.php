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
use report_feedback_tracker\local\student;
use stdClass;

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
     * @covers \get_feedback_tracker_student_data
     * @return void
     */
    public function test_get_feedback_tracker_student_data(): void {

        // Create a course and prepare the page.
        $course = $this->getDataGenerator()->create_course();
        $page = new \moodle_page();
        $page->set_context(context_course::instance($course->id));
        $page->set_pagelayout('course');

        // Create users and enrol them.
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');

        // Setup some dummy data.
        $this->setup_dummy_data($course->id, $student->id, $teacher->id);

        // Get the user data.
        $studentdata = student::get_feedback_tracker_student_data($student->id, $course->id);
        list($submissionlate, $feedbacklate, $feedbackextended, $feedbackreleased) = $studentdata->items;

        $this->assertEquals($student->username, $studentdata->student, "Assert submission is by student 1");
        $this->assertTrue(isset($feedbackreleased->submissionstatus['success']), "Assert submission is in time");
        $this->assertEquals('80/100', $feedbackreleased->grade, "Assert grade is shown correctly");
        $this->assertTrue(isset($feedbackreleased->feedbackstatus['released']), "Assert feedback is released");
        $this->assertTrue(isset($feedbackextended->feedbackstatus['late']), "Assert feedback is late");
        $this->assertTrue(isset($feedbacklate->feedbackstatus['late']), "Assert feedback is late");
        $this->assertTrue(isset($submissionlate->submissionstatus['late']), "Assert late submission");
    }

    /**
     * Test that a student does not see a course w/o an active enrolment.
     *
     * @return void
     */
    public function test_suspended_student(): void {
        // Add custom course field for academic year.
        $catid = $this->getDataGenerator()->create_custom_field_category([])->get('id');
        $this->getDataGenerator()->create_custom_field(['categoryid' => $catid, 'type' => 'text', 'shortname' => 'course_year']);

        $course = $this->getDataGenerator()->create_course(['customfield_course_year' => '2024']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setup_dummy_data($course->id, $student->id);

        // Mock current year to match course's academic year field.
        $this->mock_clock_with_frozen(strtotime("2024-10-09"));

        $this->setUser($student);

        // First check there's data when enrolment is not suspended.
        $data = student::get_feedback_tracker_student_data($student->id, SITEID);
        $this->assertEquals(1, count($data->courses));

        // Suspend student's enrolment.
        $instances = enrol_get_instances($course->id, true);
        $manualinstance = reset($instances);
        $manualplugin = enrol_get_plugin('manual');
        $manualplugin->update_user_enrol($manualinstance, $student->id, ENROL_USER_SUSPENDED);

        // Now check there's no data for course when enrolment is suspended.
        $data = student::get_feedback_tracker_student_data($student->id, SITEID);
        $this->assertEquals(0, count($data->courses));
    }

    /**
     * Setup some dummy grade data.
     *
     * @param int $courseid
     * @param int $studentid
     * @param int $teacherid
     * @return void
     */
    private function setup_dummy_data($courseid, int $studentid, int $teacherid = 0): void {
        global $CFG, $DB;

        // Create an array of modules and their grades.
        $dummymodules = [
            [
                'modulename' => 'assign',
                'name' => "Assign 1",
                'itemname' => "Grade assign item 1",
                'user' => $studentid,
                'timesubmitted' => strtotime("-6 weeks", time()),
                'grade' => "80",
                'duedate' => strtotime("-3 weeks", time()),
                'timemodified' => strtotime("-1 week", time()),
            ],
            [
                'modulename' => 'assign',
                'name' => "Assign 2",
                'itemname' => "Grade assign item 2",
                'user' => $studentid,
                'timesubmitted' => strtotime("-40 days", time()),
                'grade' => "69",
                'duedate' => strtotime("-35 days", time()),
                'timemodified' => strtotime("-16 days", time()),
            ],
            [
                'modulename' => 'assign',
                'name' => "Assign 3",
                'itemname' => "Grade assign item 3",
                'user' => $studentid,
                'timesubmitted' => strtotime("-3 days", time()),
                'grade' => "70",
                'duedate' => strtotime("-40 days", time()),
                'timemodified' => strtotime("-6 days", time()),
            ],
            [
                'modulename' => 'assign',
                'name' => "Assign 4",
                'itemname' => "Grade assign item 4",
                'user' => $studentid,
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
                        ['course' => $courseid, 'name' => $dmodule['name']]);
                } else {
                    continue;
                }
            } else {
                if (isset($dmodule['duedate'])) {
                    $module = $this->getDataGenerator()->create_module($dmodule['modulename'],
                        [
                            'course' => $courseid,
                            'name' => $dmodule['name'],
                            'duedate' => $dmodule['duedate'],
                            'assignsubmission_onlinetext_enabled' => true,
                        ]);
                } else {
                    $module = $this->getDataGenerator()->create_module($dmodule['modulename'],
                        [
                            'course' => $courseid,
                            'name' => $dmodule['name'],
                            'assignsubmission_onlinetext_enabled' => true,
                        ]);
                }
            }

            $coursemodule = get_coursemodule_from_instance($dmodule['modulename'], $module->id, $courseid);

            // If a teacher ID has been given, create a grade.
            if ($teacherid && $dmodule['modulename'] == 'assign' && $dmodule['timemodified'] > 0) {
                // Get the grade item.
                $gradeitem = $DB->get_record('grade_items', [
                    'courseid' => $courseid,
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
                    'usermodified' => $teacherid,
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

