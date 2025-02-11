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
 * Unit tests for report_feedback_tracker site.
 *
 * @package    report_feedback_tracker
 * @copyright  2025 onwards UCL
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace report_feedback_tracker;

use advanced_testcase;
use report_feedback_tracker\local\site;

/**
 * Unit tests for the report_feedback_tracker site functionality.
 */
final class feedback_tracker_site_test extends advanced_testcase {
    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test menu method.
     *
     * @covers \site::menu
     * @dataProvider menu_provider
     * @param int|null $inputyear Input year
     * @param int|null $inputterm Input term
     * @param int $currentmonth Current month for testing
     * @param int $currentyear Current year for testing
     * @param int $expectedyear Expected year in result
     * @param int $expectedterm Expected term in result
     */
    public function test_menu(
        ?int $inputyear,
        ?int $inputterm,
        int $currentmonth,
        int $currentyear,
        int $expectedyear,
        int $expectedterm): void {

        // Mock the clock.
        $this->mock_clock_with_frozen(strtotime("$currentyear-$currentmonth"));

        // Get menu data.
        $menu = site::menu($inputyear, $inputterm);

        // Verify basic structure.
        $this->assertInstanceOf(\stdClass::class, $menu);
        $this->assertObjectHasProperty('year', $menu);
        $this->assertObjectHasProperty('term', $menu);
        $this->assertObjectHasProperty('menus', $menu);
        $this->assertIsArray($menu->menus);

        // Verify year and term values.
        $this->assertEquals($expectedyear, $menu->year);
        $this->assertEquals($expectedterm, $menu->term);

        // Verify menus array structure.
        $this->assertCount(2, $menu->menus); // Should have year and term menus.

        // Verify year menu.
        $yearmenu = $menu->menus[0];
        $this->assertInstanceOf(\stdClass::class, $yearmenu);
        $this->assertObjectHasProperty('type', $yearmenu);
        $this->assertObjectHasProperty('items', $yearmenu);
        $this->assertIsArray($yearmenu->items);
        $this->assertCount(3, $yearmenu->items); // Should show 3 years.

        // Verify term menu.
        $termmenu = $menu->menus[1];
        $this->assertInstanceOf(\stdClass::class, $termmenu);
        $this->assertObjectHasProperty('type', $termmenu);
        $this->assertObjectHasProperty('items', $termmenu);
        $this->assertIsArray($termmenu->items);
        $this->assertCount(4, $termmenu->items); // Should have 4 terms.
    }

    /**
     * Data provider for test_menu.
     *
     * @return array
     */
    public static function menu_provider(): array {
        return [
            'Default values in September' => [
                'inputyear' => null,
                'inputterm' => null,
                'currentmonth' => 9,
                'currentyear' => 2025,
                'expectedyear' => 2025, // Academic year is current year when month >= 8.
                'expectedterm' => 1, // Term 1 for September.
            ],
            'Default values in January' => [
                'inputyear' => null,
                'inputterm' => null,
                'currentmonth' => 1,
                'currentyear' => 2025,
                'expectedyear' => 2024, // Academic year is previous year when month < 8.
                'expectedterm' => 2, // Term 2 for January.
            ],
            'Explicit values override defaults' => [
                'inputyear' => 2023,
                'inputterm' => 1,
                'currentmonth' => 9,
                'currentyear' => 2025,
                'expectedyear' => 2023,
                'expectedterm' => 1,
            ],
        ];
    }
}
