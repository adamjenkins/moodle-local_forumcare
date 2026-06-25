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

namespace local_forumcare\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_forumcare\local\helper;

/**
 * Apply a moderator action to a report.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moderate_report extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'The report id to action'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'One of: ok, suspend_course, suspend_site, frivolous, hide, undo'),
        ]);
    }

    /**
     * Apply the given moderator action to a report.
     *
     * Note: post deletion is intentionally not handled here. Teachers use
     * mod_forum's own existing delete-post flow (requiring mod/forum:deleteanypost)
     * rather than this plugin reimplementing post deletion.
     *
     * @param int $reportid
     * @param string $action
     * @return array
     */
    public static function execute(int $reportid, string $action): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'reportid' => $reportid,
            'action' => $action,
        ]);

        if (!in_array($params['action'], helper::VALID_MODERATION_ACTIONS, true)) {
            throw new \moodle_exception('invalidparameter', 'debug');
        }

        $report = $DB->get_record('local_forumcare_report', ['id' => $params['reportid']], '*', MUST_EXIST);
        $coursecontext = \context_course::instance($report->courseid);

        self::validate_context($coursecontext);
        require_capability('local/forumcare:reviewreports', $coursecontext);

        if ($params['action'] === 'suspend_site') {
            require_capability('local/forumcare:suspendsitewide', \context_system::instance());
        }

        helper::apply_moderation($report->id, $params['action'], $USER->id);

        return ['success' => true];
    }

    /**
     * Describe the return structure of the external service.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the action was applied successfully'),
        ]);
    }
}
