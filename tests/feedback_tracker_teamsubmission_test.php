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

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once("$CFG->dirroot/mod/assign/tests/generator.php");

use advanced_testcase;

/**
 * PHPUnit report_feedback_tracker group tests
 *
 * @package    report_feedback_tracker
 * @category   test
 * @copyright  2025 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_feedback_tracker
 */
final class feedback_tracker_teamsubmission_test extends advanced_testcase {
    /**
     * Setup
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true); // Reset global user as well.
        $this->setAdminUser();
    }

    /**
     * Test correct team submissions.
     *
     * @return void
     */
    public function test_assign_teamsubmissions(): void {
        global $DB, $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $assignrecord = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'teamsubmission' => 1,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assignrecord->id);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course); // A real assign object.
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->getDataGenerator()->create_group_member(['groupid' => $group1->id, 'userid' => $student1->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group2->id, 'userid' => $student2->id]);

        $this->add_submission($student1, $assign, 'test');
        $this->submit_for_grading($student1, $assign);
        $this->add_submission($student2, $assign, 'test');
        $this->submit_for_grading($student2, $assign);

        // Identical timestamps previously caused an error.
        $sql = "UPDATE {assign_submission} SET timemodified = " . time();
        $DB->execute($sql);

        $this->setUser($teacher);
        $renderer = $PAGE->get_renderer('report_feedback_tracker');
        $html = $renderer->render_feedback_tracker_course_report($course->id);
        $this->assertStringContainsString('2 require marking', $html);
    }

    /**
     * Add a submission.
     *
     * @param \stdClass $user
     * @param \assign $assign
     * @param string $text
     * @return void
     */
    private function add_submission(\stdClass $user, \assign $assign, string $text): void {
        $this->setUser($user);
        $plugin = $assign->get_submission_plugin_by_type('onlinetext');
        $submission = $assign->get_user_submission($user->id, true);
        $plugin->set_editor_text('onlinetext', $text, FORMAT_HTML);

        $formdata = new \stdClass();
        $formdata->onlinetext_editor = [
            'text' => $text,
            'format' => FORMAT_HTML,
        ];
        $plugin->save($submission, $formdata);
    }

    /**
     * Add a grading submission for a given user.
     *
     * @param \stdClass $user
     * @param \assign $assign
     * @return void
     */
    private function submit_for_grading(\stdClass $user, \assign $assign): void {
        $this->setUser($user);
        $assign->submit_for_grading($user->id, []);
    }
}
