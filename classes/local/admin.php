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

namespace report_feedback_tracker\local;
use coding_exception;
use course_modinfo;
use dml_exception;
use grade_item;
use html_writer;
use local_assess_type\assess_type;
use stdClass;

/**
 * This file contains the admin functions used by the feedback tracker report.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin {
    /**
     * Get a course module to the grade item where available and return a record for it.
     *
     * @param grade_item $gradeitem
     * @param course_modinfo $modinfo
     * @param array $assessmenttypes
     * @return false|stdClass
     */
    public static function get_module_record(
        grade_item $gradeitem,
        course_modinfo $modinfo,
        array $assessmenttypes
    ): false|stdClass {

        if (!$cm = self::get_cm_from_gradeitem($gradeitem)) {
            return false;
        }

        $dateformat = get_config('report_feedback_tracker', 'dateformat');

        // Get the module.
        $cmid = $cm->cmid;
        $module = $modinfo->get_cm($cmid);

        // Build the record.
        $record = new stdClass();
        $record->gradeitemid = $gradeitem->id;
        $record->name = $gradeitem->itemname; // The grade item name has more details.
        $record->moduletypeiconurl = $module->get_icon_url()->out(false);

        $record->hiddenfromstudents = !$module->visible;
        $record->hiddenfromreport = false;

        $record->cmid = $module->id;
        $record->partid = false;

        // Assessment type.
        $assessmenttype = helper::get_assessment_type($record, $assessmenttypes);
        $record->assessmenttype = $assessmenttype['type'];
        $record->selectedassessmenttypelabel = helper::get_selected_assess_type_label($record->assessmenttype);
        $record->locked = $assessmenttype['locked'];
        $record->formative = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_FORMATIVE;
        $record->summative = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_SUMMATIVE;
        $record->dummy = (int) $assessmenttype['type'] === assess_type::ASSESS_TYPE_DUMMY;
        $record->notset = !$record->formative && !$record->summative && !$record->dummy;

        $record->assesstypes = helper::get_assess_types(isset($record->assessmenttype) ? $record->assessmenttype : null);

        $record->modname = $module->modname;

        // Dates.
        $duedate = helper::get_duedate($module);
        $record->duedate = $duedate ? date($dateformat, $duedate) : false;
        // The raw date is needed for sorting.
        $record->feedbackduedateraw = $duedate ? helper::calculate_feedback_duedate($gradeitem->courseid, $duedate) : 9999999999;
        $record->feedbackduedate = $duedate ? date($dateformat, $record->feedbackduedateraw) : false;
        $record->markoverdue = false;

        // Student data.
        $record->overrides = helper::get_overrides($module);
        $record->overridesurl = helper::get_overrides_url($module);
        $record->submissions = count(helper::get_submissions($module));

        // Grades and markings.
        $grades = helper::get_grade_grades($gradeitem);
        $record->requiredfeedbacks = max($record->submissions - $grades, 0);
        $record->feedbackpercentage = $record->submissions ? round($grades / $record->submissions * 100, 2) : 0;
        $record->url = $module->get_url();

        return $record;
    }

    /**
     * Get a course module ID from a grade item where available.
     *
     * @param grade_item $gradeitem
     * @return false|mixed
     * @throws dml_exception
     */
    public static function get_cm_from_gradeitem(grade_item $gradeitem) {
        global $DB;

        // SQL query to get the course module ID from a grade item.
        $sql = "
                    SELECT cm.id AS cmid
                        FROM {course_modules} cm
                        JOIN {modules} m ON cm.module = m.id
                        JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
                    WHERE gi.id = :gradeitemid
                ";

        // Execute the query.
        return $DB->get_record_sql($sql, ['gradeitemid' => $gradeitem->id]);
    }

}
