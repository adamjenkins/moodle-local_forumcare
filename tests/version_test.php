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

namespace local_forumcare;

/**
 * Tests for the plugin's version metadata.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class version_test extends \basic_testcase {
    /** @var int[] First release build of each Moodle branch (from its version.php). */
    private const BRANCH_FLOORS = [
        500 => 2025041400,
        501 => 2025100600,
        502 => 2026042000,
    ];

    /**
     * $plugin->requires must not admit a Moodle older than the lowest supported branch.
     */
    public function test_requires_matches_lowest_supported_branch(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');

        $lowest = (int) $plugin->supported[0];
        $this->assertArrayHasKey($lowest, self::BRANCH_FLOORS, 'Add the floor build of branch ' . $lowest);
        $this->assertGreaterThanOrEqual(self::BRANCH_FLOORS[$lowest], $plugin->requires);
    }
}
