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
 * Data generator for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_forumcare_generator extends component_generator_base {
    /**
     * Create a report reason: site-wide when no courseid is given, otherwise for that course.
     *
     * @param array $record name (required), courseid, enabled, sortorder
     * @return stdClass The reason record.
     */
    public function create_reason(array $record): stdClass {
        global $DB;
        $reason = (object) [
            'name' => $record['name'],
            'courseid' => empty($record['courseid']) ? null : (int) $record['courseid'],
            'enabled' => isset($record['enabled']) ? (int) (bool) $record['enabled'] : 1,
            'sortorder' => (int) ($record['sortorder'] ?? 0),
            'timecreated' => time(),
        ];
        $reason->id = $DB->insert_record('local_forumcare_reason', $reason);
        return $reason;
    }

    /**
     * Turn reporting on (or off) for a forum, as its settings form does.
     *
     * @param array $record forumid (required), enabled (default 1)
     * @return void
     */
    public function create_forum_settings(array $record): void {
        \local_forumcare\local\helper::set_forum_enabled((int) $record['forumid'], (bool) ($record['enabled'] ?? 1));
    }
}
