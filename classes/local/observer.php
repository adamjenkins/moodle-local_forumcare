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
 * Event observers keeping forum care data consistent with course lifecycle events.
 *
 * Forum care rows reference forums, courses and posts by id but Moodle never
 * tells local plugins directly when those are deleted, so we listen to the
 * relevant core events and clean up after them.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A course module was deleted: drop the per-forum settings and any
     * reports filed against posts in that forum.
     *
     * @param \core\event\course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        if ($event->other['modulename'] !== 'forum') {
            return;
        }

        $forumid = (int) $event->other['instanceid'];
        $DB->delete_records('local_forumcare_forum', ['forumid' => $forumid]);
        $DB->delete_records('local_forumcare_report', ['forumid' => $forumid]);
        self::sweep_orphaned_hidden();
    }

    /**
     * Remove hidden-post backups whose underlying forum post no longer exists.
     *
     * The _hidden table keys only on postid, and module/course deletion removes
     * the forum's posts before our observers run, so orphaned backups are cleaned
     * up by sweeping against the post table rather than joining to a now-empty
     * forum. Any such row is useless anyway - the original post it backed up is gone.
     *
     * @return void
     */
    private static function sweep_orphaned_hidden(): void {
        global $DB;
        $DB->delete_records_select(
            'local_forumcare_hidden',
            'postid NOT IN (SELECT id FROM {forum_posts})'
        );
    }

    /**
     * A course was deleted: drop its reports, course-level settings and
     * course-specific reasons. Course deletion removes the course's forums
     * without firing course_module_deleted for them (remove_course_contents
     * deletes modules directly), so per-forum rows are cleaned up with an
     * orphan sweep against the forum table instead.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = (int) $event->objectid;
        $DB->delete_records('local_forumcare_report', ['courseid' => $courseid]);
        $DB->delete_records('local_forumcare_course', ['courseid' => $courseid]);
        $DB->delete_records('local_forumcare_reason', ['courseid' => $courseid]);
        $DB->delete_records_select('local_forumcare_forum', 'forumid NOT IN (SELECT id FROM {forum})');
        self::sweep_orphaned_hidden();
    }

    /**
     * A course was reset: forum resets bulk-delete posts without firing
     * per-post events, so remove any reports left pointing at posts that
     * no longer exist.
     *
     * @param \core\event\course_reset_ended $event
     * @return void
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event): void {
        global $DB;

        $DB->delete_records_select(
            'local_forumcare_report',
            'courseid = :courseid AND postid NOT IN (SELECT id FROM {forum_posts})',
            ['courseid' => (int) $event->courseid]
        );
        self::sweep_orphaned_hidden();
    }
}
