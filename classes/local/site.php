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

use block_portico_enrolments;
use core_course\external\course_summary_exporter;
use course_modinfo;
use grade_item;
use local_assess_type\assess_type;
use moodle_url;
use stdClass;

/**
 * Feedback tracker site level report for non-students.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class site {

    /**
     * Autumn academic term.
     */
    const TERM_AUTUMN = 1;

    /**
     * Spring academic term.
     */
    const TERM_SPRING = 2;

    /**
     * Summer academic term.
     */
    const TERM_SUMMER = 3;



    /**
     * Return the data for the site level report.
     *
     * @return stdClass
     */
    public static function get_feedback_tracker_site_data(): stdClass {
        global $USER;

        // Start an optional timer.
        $time = optional_param('time', null, PARAM_INT);
        if ($time) {
            $starttime = microtime(true);
        }

        // Template for mustache.
        $data = new stdClass();
        $data->staffdata = true;

        $data->courses = [];

        // Year and term menus.
        $year = optional_param('year', null, PARAM_INT);
        $term = optional_param('term', null, PARAM_INT);
        $menus = self::menu($year, $term);
        $data->menus = $menus->menus;

        // Years.
        $data->year = $menus->year;
        $data->yearname = substr($menus->year, -2). "/" . substr($menus->year + 1, -2);

        // Terms.
        $data->term = $menus->term;
        $data->termname = get_string('t' . $menus->term, 'report_feedback_tracker');
        $data->termcode = 't' . $menus->term;

        // Courses.
        $courses = enrol_get_all_users_courses($USER->id, false, null, 'fullname');
        foreach ($courses as $course) {
            // Skip hidden.
            if (!$course->visible) {
                continue;
            }

            // Only show courses for the selected year.
            if ((int) helper::get_academic_year($course->id) === $data->year) {
                // Only show courses for the selected academic year and term and where the user is a teacher.
                list($courseacademicyears, $courseterms) = self::get_course_academic_years_and_terms($course);
                if (in_array($data->year, $courseacademicyears) &&
                        in_array($data->termcode, $courseterms[$data->year]) &&
                        helper::is_teacher($course)) {
                    $courseitem = self::build_courseitem($course);

                    // Show only courses with assessments to show.
                    if (isset($courseitem->items)) {
                        $data->courses[] = $courseitem;
                    }
                }
            }
        }

        // If timer option is set show the execution time.
        if ($time) {
            $endtime = microtime(true);
            $executiontime = $endtime - $starttime;

            $data->executiontime = "Execution time: " . number_format($executiontime, 4) . " seconds\n";
        }
        return $data;
    }

    /**
     * Menu data for the report.
     *
     * @param int|null $year The selected year.
     * @param int|null $term The selected term.
     * @return stdClass The menu data.
     */
    public static function menu(?int $year = null, ?int $term = null): stdClass {
        // Get the current month and year.
        $clock = \core\di::get(\core\clock::class);
        $currentmonth = $clock->now()->format('n');
        $currentyear = $clock->now()->format('Y');

        // Check if we have a year from url, or set a default.
        $defaultyear = ($currentmonth < 8) ? $currentyear - 1 : $currentyear;
        $year = $year ?? $defaultyear;

        // Check if we have a term from url, or set a default.
        $term = $term ?: self::current_term($currentmonth);

        // Template for mustache.
        $template = new stdClass();
        $template->year = $year;
        $template->term = $term;

        // Years menu.
        $yearmenu = new stdClass();
        $yearmenu->type = get_string('year', 'report_feedback_tracker');
        $yearmenu->items = self::years_menu($year, $term);

        // Terms menu.
        $termmenu = new stdClass();
        $termmenu->type = get_string('term', 'report_feedback_tracker');
        $termmenu->items = self::terms_menu($year, $term);

        // Add menus.
        $template->menus = [$yearmenu, $termmenu];

        return $template;
    }

    /**
     * Return the current academic term.
     *
     * @param int $month The current month (1-12).
     * @return int The current term (1-3).
     */
    public static function current_term(int $month): int {
        if ($month <= 3) {
            return self::TERM_SPRING;
        }
        if ($month <= 8) {
            return self::TERM_SUMMER;
        }
        return self::TERM_AUTUMN;
    }

    /**
     * Terms menu.
     *
     * @param int $year The selected year.
     * @param int $term The selected term.
     * @return array The terms menu data.
     */
    public static function terms_menu(int $year, int $term): array {
        $terms = [];
        for ($i = 1; $i <= 4; $i++) {
            $template = new stdClass();
            $template->value = get_string('t'.$i, 'report_feedback_tracker');
            $template->url = new moodle_url('/report/feedback_tracker/site.php', ['year' => $year, 'term' => $i]);
            $template->selected = ($term === $i);
            $terms[] = $template;
        }

        return $terms;
    }

    /**
     * Years menu.
     *
     * @param int $year The selected year.
     * @param int $term The selected term.
     * @return array The years menu data.
     */
    public static function years_menu(int $year, int $term): array {
        // Get the current month and year.
        $clock = \core\di::get(\core\clock::class);
        $currentmonth = $clock->now()->format('n');
        $currentyear = $clock->now()->format('Y');

        // Menu start year. Note UCL years start in Aug/8th month.
        $menustart = ($currentmonth < 8) ? $currentyear - 1 : $currentyear;

        $years = [];
        for ($i = 0; $i < 3; $i++) {
            $yearstart = $menustart - $i;
            $yearend = $yearstart + 1;

            $template = new stdClass();
            $template->value = substr($yearstart, -2) . "/" . substr($yearend, -2);
            $template->url = new moodle_url('/report/feedback_tracker/site.php', ['year' => $yearstart, 'term' => $term]);
            $template->selected = ($yearstart === $year);
            $years[] = $template;
        }

        return $years;
    }

    /**
     * Build a course item.
     *
     * @param stdClass $course
     * @return stdClass|null
     */
    private static function build_courseitem(stdClass $course): ?stdClass {
        $gradeitems = grade_item::fetch_all(['courseid' => $course->id]);
        // Only build course items from courses with grade items.
        if (!$gradeitems) {
            return null;
        }

        $modinfo = get_fast_modinfo($course->id);
        $assesstypes = helper::get_assessment_types($course->id);

        $courseitem = new stdClass();
        $courseitem->url = helper::get_course_url($course->id);
        $courseitem->image = course_summary_exporter::get_course_image($course);
        $courseitem->fullname = $course->fullname;
        $courseitem->reporturl = new moodle_url("/report/feedback_tracker/", ['id' => $course->id]);

        foreach ($gradeitems as $gradeitem) {
            // Get course module ID for the grade item where it exists.
            $cmid = helper::get_cmid($gradeitem->id);
            $assesstype = helper::get_assesstype($gradeitem->id,  $cmid, $assesstypes);

            // Process summative modules and manual grade items only.
            if (((int) $assesstype->type === assess_type::ASSESS_TYPE_SUMMATIVE) &&
                    (($gradeitem->itemtype == 'mod') || ($gradeitem->itemtype === 'manual'))) {
                if ($gradeitem->itemmodule === 'turnitintooltwo') {
                    self::build_turnitin_gradeitems($courseitem, $gradeitem, $modinfo, $assesstypes);
                } else {
                    self::build_gradeitem($courseitem, $gradeitem, $modinfo, $assesstype);
                }
            }
        }

        // Sort assessments by feedback due date.
        if (isset($courseitem->items)) {
            usort($courseitem->items, function($a, $b) {
                return $a->feedbackduedateraw <=> $b->feedbackduedateraw;
            });
        }

        return $courseitem;
    }

    /**
     * Build a grade item.
     *
     * @param stdClass $courseitem
     * @param grade_item $gradeitem
     * @param course_modinfo $modinfo
     * @param stdClass $assesstype
     * @return void
     */
    private static function build_gradeitem(stdClass $courseitem,
                                            grade_item $gradeitem,
                                            course_modinfo $modinfo,
                                            stdClass $assesstype
    ): void {
        // Get the corresponding course module where it exists.
        if (($cm = admin::get_module_data($modinfo, $gradeitem))
                && !$cm->hiddenfromstudents) {
            helper::add_assesstype($cm, $assesstype);
            helper::add_additional_data($cm);

            $courseitem->items[] = $cm;
        }
    }

    /**
     * Build grade items from Turnitin parts.
     *
     * @param stdClass $courseitem
     * @param grade_item $gradeitem
     * @param course_modinfo $modinfo
     * @param array $assesstypes
     * @return void
     */
    private static function build_turnitin_gradeitems(stdClass $courseitem,
                                            grade_item $gradeitem,
                                            course_modinfo $modinfo,
                                            array $assesstypes
    ): void {
        // Get the corresponding course module where it exists.
        if (($cm = admin::get_module_data($modinfo, $gradeitem))
                && !$cm->hiddenfromstudents) {
            // Add separate data for each summative Turnitin part.
            helper::add_ttt_data($courseitem, $gradeitem, $cm, $assesstypes, assess_type::ASSESS_TYPE_SUMMATIVE);
        }
    }

    /**
     * Return the academic years and terms a course is mapped to.
     *
     * @param stdClass $course
     * @return array[]
     */
    public static function get_course_academic_years_and_terms(stdClass $course): array {
        $mappings = block_portico_enrolments\manager::get_modocc_mappings($course->id);

        foreach ($mappings as $mapping) {
            $ayitem = new stdClass();
            $ayitem->courseyear = $mapping->mod_occ_year_code;
            $ayitem->rawterms = $mapping->mod_occ_psl_code;
            $ayitem->courseterms = self::parse_mappingtermcode($mapping->mod_occ_psl_code);
            $ayitems[] = $ayitem;
        }

        // If Portico does not provide an academic year try to get it from Moodle data.
        // As then there is no term specified show it in the "other" term (t4).
        if (!isset($ayitems) || empty($ayitems)) {
            $ayitem = new stdClass();
            $ayitem->courseyear = helper::get_academic_year($course->id);
            $ayitem->courseterms = [4 => 't4'];
            $ayitems[] = $ayitem;
        }

        $courseacademicyears = [];
        $courseterms = [];
        foreach ($ayitems as $ayitem) {
            $courseacademicyears[$ayitem->courseyear] = $ayitem->courseyear;
            // Merge possible multiple course terms.
            if (isset($courseterms[$ayitem->courseyear])) {
                $courseterms[$ayitem->courseyear] = array_merge($courseterms[$ayitem->courseyear], $ayitem->courseterms);
            } else {
                $courseterms[$ayitem->courseyear] = $ayitem->courseterms;
            }
        }

        return [$courseacademicyears, $courseterms];
    }

    /**
     * Parse the term string returned from portico enrolment mapping.
     *
     * @param string $rawterm
     * @return array
     */
    private static function parse_mappingtermcode(string $rawterm): array {
        $courseterms = [];
        if ($rawterm) {
            // A rawterm string may contain several term IDs like 'T1/2' so we need to parse it.
            // Use a regular expression to match all numbers in the string.
            preg_match_all('/\d+/', $rawterm, $matches);
            // Get the matched numbers as an array of integers.
            $processterms = array_map('intval', $matches[0]);
            foreach ($processterms as $processterm) {
                $courseterms[$processterm] = 't'.$processterm;
            }
        }
        return $courseterms;
    }
}
