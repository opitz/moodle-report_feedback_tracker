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
 * PHPUnit report_feedback_tracker tests
 *
 * @package    report_feedback_tracker
 * @category   test
 * @copyright  2025 onwards UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_feedback_tracker
 */

namespace report_feedback_tracker;

use advanced_testcase;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\module_helper_factory;

/**
 * Unit tests for the report_feedback_tracker coursework functionality.
 */
final class feedback_tracker_coursework_test extends advanced_testcase {
    public function setUp(): void {
        global $CFG;

        require_once($CFG->dirroot . '/report/feedback_tracker/lib.php');
        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('supportlti', true, 'report_feedback_tracker');
    }

    /**
     * Test admin::get_duedate() for coursework.
     *
     * Covers:
     *  - Missing coursework row → 0
     *  - Present deadline → expected timestamp
     *
     * @covers \report_feedback_tracker\local\module_helper::get_duedate
     */
    public function test_admin_get_duedate_coursework_missing_false_and_present(): void {
        global $DB;

        // Create course.
        $course = $this->getDataGenerator()->create_course();

        // Create coursework activity.
        $coursework = $this->getDataGenerator()->create_module('coursework', [
            'course' => $course->id,
            'name' => 'Coursework Due Date Test',
            'deadline' => 1829001600,
        ]);

        // Get cm_info.
        $cm = get_coursemodule_from_instance('coursework', $coursework->id);
        $cminfo = \cm_info::create($cm);
        $modulehelper = module_helper_factory::create($cminfo);

        // Case 1: Deadline present → should return timestamp.
        $expected = 1829001600;

        $this->assertEquals(
            $expected,
            $modulehelper->get_duedate(),
            'Expected timestamp when coursework deadline is present'
        );

        // Case 2: Missing coursework DB row → should return 0.
        $DB->delete_records('coursework', ['id' => $coursework->id]);
        $this->assertEquals(
            0,
            $modulehelper->get_duedate(),
            'Expected 0 when coursework record is missing'
        );
    }
}
