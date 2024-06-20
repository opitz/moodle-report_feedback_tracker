<?php
/**
 * Steps definitions related to editing mode.
 *
 * @package    report_feedback_tracker
 * @copyright  2024 UCL <m.opitz@ucl.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

class behat_report_feedback_tracker extends behat_base {

    /**
     * Turn editing mode on or off.
     *
     * @When /^I (turn|switch) editing mode (on|off)$/
     * @param string $state
     */
    public function i_turn_editing_mode($state) {
        $editbutton = $state == 'on' ? get_string('turneditingon') : get_string('turneditingoff');
        $this->execute('behat_general::i_click_on', array($editbutton, "button"));
    }

    /**
     * @When I select :option from the :dropdown dropdown
     * @param string $option
     * @param string $dropdown
     */
    public function iSelectFromTheDropdown($option, $dropdown) {
        $this->getSession()->getPage()->selectFieldOption($dropdown, $option);
    }


}
