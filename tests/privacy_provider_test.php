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

use report_feedback_tracker\privacy\provider;

/**
 * Unit testing privacy provider class for feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 onwards UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class privacy_provider_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test get_reason.
     *
     * @covers \report_feedback_tracker\privacy\provider::get_reason()
     * @return void
     */
    public function test_get_reason(): void {
        $expected = "The Feedback tracker report plugin does not store any personal data.";
        $reason = get_string(provider::get_reason(), 'report_feedback_tracker');
        $this->assertEquals($expected, $reason);
    }
}
