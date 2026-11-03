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

namespace local_forumcare\task;

/**
 * Scheduled AutoReport task: hands the posts written since the last run to
 * ad hoc scans (\local_forumcare\task\autoreport_scan_posts).
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autoreport extends \core\task\scheduled_task {
    /**
     * Task name shown to admins.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskautoreport', 'local_forumcare');
    }

    /**
     * Queue the scans.
     *
     * @return void
     */
    public function execute() {
        $queued = \local_forumcare\local\autoreport::queue_new_posts();
        mtrace('AutoReport: queued ' . $queued . ' post scan task(s).');
    }
}
