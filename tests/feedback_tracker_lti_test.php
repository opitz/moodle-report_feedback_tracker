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
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \report_feedback_tracker
 */

namespace report_feedback_tracker;

use advanced_testcase;
use local_assess_type\assess_type;
use ltiservice_gradebookservices\local\resources\lineitem;
use ltiservice_gradebookservices\local\resources\scores;
use ltiservice_gradebookservices\local\service\gradebookservices;
use report_feedback_tracker\local\admin;
use report_feedback_tracker\local\module_helper_factory;
use report_feedback_tracker\local\student;
use report_feedback_tracker\task\process_export;

/**
 * Unit tests for the report_feedback_tracker LTI functionality.
 */
final class feedback_tracker_lti_test extends advanced_testcase {
    public function setUp(): void {
        global $CFG;

        require_once($CFG->dirroot . '/report/feedback_tracker/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('supportlti', true, 'report_feedback_tracker');
    }

    /**
     * Test the user/student data.
     *
     * @covers \get_feedback_tracker_student_data
     * @return void
     */
    public function test_get_feedback_tracker_student_data(): void {
        global $DB;

        if (!class_exists('\ltiservice_gradebookservices\hook\scorereceived')) {
            $this->markTestSkipped('Requires MDL-87480 to run.');
        }

        $type = new \stdClass();
        $type->state = LTI_TOOL_STATE_CONFIGURED;
        $type->name = "Test tool";
        $type->description = "Example description";
        $type->clientid = "Test client ID";
        $type->baseurl = $this->getExternalTestFileUrl('/test.html');

        $config = new \stdClass();
        $config->ltiservice_gradesynchronization = 2;
        $typeid = lti_add_type($type, $config);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course);

        $resourceid = 'test-resource-id';
        $tag = 'tag';
        $lti = ['course' => $course->id,
            'typeid' => $typeid,
            'instructorchoiceacceptgrades' => LTI_SETTING_ALWAYS,
            'grade' => 10,
            'lineitemresourceid' => $resourceid,
            'lineitemtag' => $tag,
            'name' => 'LTI Activity',
        ];

        $cm = $this->getDataGenerator()->create_module('lti', $lti);
        $cminfo = \cm_info::create(get_coursemodule_from_instance('lti', $cm->id));
        $modulehelper = module_helper_factory::create($cminfo);

        assess_type::update_type($course->id, assess_type::ASSESS_TYPE_SUMMATIVE, $cminfo->id);

        $gbservice = new gradebookservices();
        $gbservice->set_type(lti_get_type($typeid));
        $gradeitems = $gbservice->get_lineitems($course->id, null, null, null, null, null, $typeid);
        $lineitem = gradebookservices::item_for_json($gradeitems[1][0], '', $typeid);
        $lineitemresource = new lineitem($gbservice);

        $this->set_server_for_put($course, $typeid, $lineitem);

        $response = new \mod_lti\local\ltiservice\response();
        $lineitem->resourceId = $resourceid . 'modified';
        $lineitem->tag = $tag . 'modified';
        $response->set_request_data(json_encode($lineitem));
        $lineitemresource->execute($response);

        $reportfeedbacktrackerltis = $DB->get_records('report_feedback_tracker_lti');
        $this->assertCount(1, $reportfeedbacktrackerltis);
        $reportfeedbacktrackerlti = reset($reportfeedbacktrackerltis);
        $this->assertEquals(null, $reportfeedbacktrackerlti->gradesreleased);
        $this->assertEquals(null, $reportfeedbacktrackerlti->enddatetime);

        $lineitem->endDateTime = '2027-12-17T00:00:00Z';
        $lineitem->gradesReleased = true;
        $response->set_request_data(json_encode($lineitem));
        $lineitemresource->execute($response);

        $reportfeedbacktrackerltis = $DB->get_records('report_feedback_tracker_lti');
        $this->assertCount(1, $reportfeedbacktrackerltis);
        $reportfeedbacktrackerlti = reset($reportfeedbacktrackerltis);
        $this->assertEquals(1829001600, $reportfeedbacktrackerlti->enddatetime);
        $this->assertGreaterThanOrEqual(time(), $reportfeedbacktrackerlti->gradesreleased);

        $reportfeedbacktrackerltiusrs = $DB->get_records('report_feedback_tracker_lti_usr');
        $this->assertCount(0, $reportfeedbacktrackerltiusrs);

        $score = new scores($gbservice);
        $_SERVER['REQUEST_METHOD'] = \mod_lti\local\ltiservice\resource_base::HTTP_POST;
        $_SERVER['PATH_INFO'] = "/$course->id/lineitems/{$gradeitems[1][0]->id}/lineitem/scores?type_id=$typeid";
        $token = lti_new_access_token($typeid, ['https://purl.imsglobal.org/spec/lti-ags/scope/score']);
        $_SERVER['HTTP_Authorization'] = 'Bearer ' . $token->token;
        $_GET['type_id'] = (string)$typeid;
        $requestdata = [
            'scoreGiven' => "8.0",
            'scoreMaximum' => "10.0",
            'activityProgress' => "Completed",
            'timestamp' => "2024-08-07T18:54:36.736+00:00",
            'submission' => ['submittedAt' => '2027-12-16T00:00:00Z'],
            'gradingProgress' => "FullyGraded",
            'userId' => $user->id,
        ];
        $response = new \mod_lti\local\ltiservice\response();
        $response->set_content_type('application/vnd.ims.lis.v1.score+json');
        $response->set_request_data(json_encode($requestdata));
        $score->execute($response);

        $reportfeedbacktrackerltiusrs = $DB->get_records('report_feedback_tracker_lti_usr');
        $this->assertCount(1, $reportfeedbacktrackerltiusrs);
        $reportfeedbacktrackerltiusr = reset($reportfeedbacktrackerltiusrs);

        $this->assertEquals(1828915200, $reportfeedbacktrackerltiusr->submittedat);

        $this->assertEquals('1829001600', $modulehelper->get_duedate());
        $m = $modulehelper->get_module_submissions();

        $m = reset($m);
        $this->assertEquals('1828915200', $m->submissiondatetime);
        $this->assertEquals($user->id, $m->userid);

        $this->assertEquals('1828915200', module_helper_factory::create($cminfo)->get_submissiondate($user->id, $cminfo->instance));

        $task = new process_export();
        $task->phpu_set_courseid($course->id);

        $module = $task->get_course_modules()->current();
        $cm = \cm_info::create(get_coursemodule_from_instance($module->modname, $module->instance));

        $mods = [];
        foreach ($task->get_course_modules() as $module) {
            $mods[$module->id] = $module;
        }

        $this->assertEquals(1, count($mods));

        $submissions = module_helper_factory::create($cm)->get_module_submissions();

        $record = $task->process_submission(reset($submissions), $module);

        $this->assertEquals("LTI Activity", $record->assessmentname);
        $this->assertEquals(1829001600, $record->duedatetime);
        $this->assertEquals(1828915200, $record->submissiondatetime);
        $this->assertLessThanOrEqual(time(), $record->feedbackdatetime);
        $this->assertEquals($user->id, $record->submissionuserid);
        $this->assertEquals('lti', $record->assessmentmod);
    }

    /**
     * Test admin::get_duedate() returns 0 when no LTI record exists,
     * returns 0 when enddatetime is missing,
     * and returns the expected timestamp when present.
     *
     * @covers \report_feedback_tracker\local\module_helper_factory::create($cminfo)->get_duedate()
     * @return void
     */
    public function test_admin_get_duedate_missing_and_present(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a course + LTI activity.
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $course->id,
            'name'   => 'LTI Due Date Test',
        ]);

        $cminfo = \cm_info::create(get_coursemodule_from_instance('lti', $lti->id));
        $modulehelper = module_helper_factory::create($cminfo);

        // Case 1: No record exists in report_feedback_tracker_lti.
        $this->assertFalse(
            $DB->record_exists('report_feedback_tracker_lti', ['instanceid' => $lti->id])
        );

        $this->assertEquals(
            0,
            $modulehelper->get_duedate(),
            'Expected duedate to be 0 when no LTI tracking record exists'
        );

        // Case 2: Record exists but enddatetime is NULL.
        $DB->insert_record('report_feedback_tracker_lti', (object)[
            'instanceid'     => $lti->id,
            'enddatetime'    => null,
            'gradesreleased' => null,
        ]);

        $this->assertEquals(
            0,
            $modulehelper->get_duedate(),
            'Expected duedate to be 0 when enddatetime is missing'
        );

        // Case 3: Record exists and enddatetime is set.
        $expected = 1829001600;

        $DB->set_field(
            'report_feedback_tracker_lti',
            'enddatetime',
            $expected,
            ['instanceid' => $lti->id]
        );

        $this->assertEquals(
            $expected,
            $modulehelper->get_duedate(),
            'Expected duedate to match enddatetime when present'
        );
    }

    /**
     * Sets the server info and get to be configured for a PUT operation,
     * including having a proper auth token attached.
     *
     * @param object $course course where to add the lti instance.
     * @param int $typeid
     * @param object $lineitem
     */
    private function set_server_for_put(object $course, int $typeid, object $lineitem) {
        $_SERVER['REQUEST_METHOD'] = \mod_lti\local\ltiservice\resource_base::HTTP_PUT;
        $_SERVER['PATH_INFO'] = "/$course->id/lineitems$lineitem->id";

        $token = lti_new_access_token($typeid, ['https://purl.imsglobal.org/spec/lti-ags/scope/lineitem']);
        $_SERVER['HTTP_Authorization'] = 'Bearer ' . $token->token;
        $_GET['type_id'] = (string)$typeid;
    }
}
