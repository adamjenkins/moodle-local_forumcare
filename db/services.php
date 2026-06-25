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
 * Web service definitions for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_forumcare_get_reasons' => [
        'classname' => 'local_forumcare\external\get_reasons',
        'methodname' => 'execute',
        'description' => 'Return the list of enabled report reasons',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_forumcare_submit_report' => [
        'classname' => 'local_forumcare\external\submit_report',
        'methodname' => 'execute',
        'description' => 'Submit a report against a forum post',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/forumcare:report',
    ],
    'local_forumcare_moderate_report' => [
        'classname' => 'local_forumcare\external\moderate_report',
        'methodname' => 'execute',
        'description' => 'Apply a moderator action to a forum report',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/forumcare:reviewreports',
    ],
    'local_forumcare_get_post_report_status' => [
        'classname' => 'local_forumcare\external\get_post_report_status',
        'methodname' => 'execute',
        'description' => 'Return whether each post is the user\'s own and/or already reported by them',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/forumcare:report',
    ],
];
