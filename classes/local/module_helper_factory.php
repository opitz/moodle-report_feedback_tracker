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

use cm_info;

/**
 * The helper factory class.
 *
 * @package    report_feedback_tracker
 * @copyright  2026 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author      m.opitz <m.opitz@ucl.ac.uk>
 */
class module_helper_factory {
    /**
     * Creator
     *
     * @param cm_info $module
     * @return mixed
     */
    public static function create(cm_info $module) {
        $fullclassname = __NAMESPACE__ . "\\mod_{$module->modname}_helper";

        if (!$fullclassname) {
            throw new \moodle_exception('error:moduleclassnotfound', 'report_feedback_tracker', '', $fullclassname);
        }

        return new $fullclassname($module);
    }
}
