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

namespace local_forumcare\local;

/**
 * Hooks API callbacks for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Inject the report-post AMD module on forum discussion/view pages.
     *
     * mod_forum has no hook or callback for adding per-post action menu items,
     * so the report link is added client-side via JS on the relevant page types.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     * @return void
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE;

        if (!isloggedin() || isguestuser()) {
            return;
        }

        $forumpagetypes = ['mod-forum-view', 'mod-forum-discuss'];
        if (!in_array($PAGE->pagetype, $forumpagetypes) || $PAGE->cm === null || $PAGE->cm->modname !== 'forum') {
            return;
        }

        if (!has_capability('local/forumcare:report', $PAGE->context)) {
            return;
        }

        if (!helper::can_report_in_forum($PAGE->cm->instance)) {
            return;
        }

        $PAGE->requires->js_call_amd('local_forumcare/report', 'init', [$PAGE->course->id]);
    }
}
