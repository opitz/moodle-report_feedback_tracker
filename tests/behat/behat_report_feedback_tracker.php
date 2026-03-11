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
 * Steps definitions related to editing mode.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Mink\Exception\ElementNotFoundException;
use dml_exception;

/**
 * Steps definitions related to editing mode.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_feedback_tracker extends behat_base {
    /**
     * Clicks the edit button for the given module name.
     *
     * Example: I click on the "Edit" button in the "Test quiz" module
     *
     * @When /^I click on the "Edit" button in the "([^"]+)" module$/
     * @param string $modulename The name of the module.
     * @throws ElementNotFoundException If the module or button cannot be found.
     */
    public function i_click_on_the_edit_button_in_the_module($modulename) {
        $session = $this->getSession();
        $page = $session->getPage();

        // Locate the module by its name.
        $module = $page->find('xpath', "//div[@class='module' and @data-modulename='{$modulename}']");
        if (!$module) {
            throw new ElementNotFoundException($session, 'module', 'data-modulename', $modulename);
        }

        // Find the edit button within the module.
        $button = $module->find('css', '.js-edit-tracker-data');
        if (!$button) {
            throw new ElementNotFoundException($session, 'button', 'css', '.js-edit-tracker-data');
        }

        // Click the button.
        $button->click();
    }

    /**
     * Select an option from a dropdown menu.
     *
     * @When I select :option from the :dropdown dropdown
     * @param string $option
     * @param string $dropdown
     */
    public function i_select_from_the_dropdown($option, $dropdown) {
        $this->getSession()->getPage()->selectFieldOption($dropdown, $option);
    }

    /**
     * Open an absolute plugin path.
     *
     * @When /^I am on "([^"]+)"$/
     * @param string $path
     * @return void
     */
    public function i_am_on_path(string $path): void {
        $this->getSession()->visit($this->locate_path($path));
    }

    /**
     * Assert that no guest report-viewed event has been logged.
     *
     * @Then /^no feedback tracker report viewed event should exist for guest$/
     * @return void
     * @throws dml_exception
     */
    public function no_guest_report_viewed_event_should_exist(): void {
        global $DB;

        $count = $DB->count_records_select(
            'logstore_standard_log',
            'eventname = :eventname AND userid = :userid',
            [
                'eventname' => '\\report_feedback_tracker\\event\\report_viewed',
                'userid' => 0,
            ]
        );

        if ($count > 0) {
            throw new \Exception('Expected no guest report_viewed events, found: ' . $count);
        }
    }
}
