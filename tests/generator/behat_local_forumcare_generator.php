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
 * Behat data generator for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_forumcare_generator extends behat_generator_base {
    /**
     * The entities Behat can create, as `the following "local_forumcare > <entity>" exist:`.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'reasons' => [
                'singular' => 'reason',
                'datagenerator' => 'reason',
                'required' => ['name'],
                'switchids' => ['course' => 'courseid'],
            ],
            'forums' => [
                'singular' => 'forum',
                'datagenerator' => 'forum_settings',
                'required' => ['forum'],
                'switchids' => ['forum' => 'forumid'],
            ],
        ];
    }

    /**
     * Get a forum id from its activity idnumber or name.
     *
     * @param string $idnumberorname
     * @return int
     */
    protected function get_forum_id(string $idnumberorname): int {
        return $this->get_cm_by_activity_name('forum', $idnumberorname)->instance;
    }
}
