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

namespace local_forumcare\event;

/**
 * The user suspended event.
 *
 * @property-read array $other {
 *      - string scope: Either 'course' or 'site'.
 *      - int courseid: The course id, when scope is 'course'.
 *      - bool automatic: Whether the suspension was triggered automatically by threshold.
 * }
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_suspended extends \core\event\base {
    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $scope = $this->other['scope'];
        $automatic = !empty($this->other['automatic']) ? 'automatically' : 'manually';
        return "The user with id '$this->relateduserid' was $automatic suspended ($scope scope) due to forum " .
            "reports (triggered by user id '$this->userid').";
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventusersuspended', 'local_forumcare');
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['scope'])) {
            throw new \coding_exception('The \'scope\' value must be set in other.');
        }
        if (empty($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' value must be set.');
        }
    }

    /**
     * Used to map fields for backup/restore.
     *
     * @return false
     */
    public static function get_other_mapping() {
        return false;
    }
}
