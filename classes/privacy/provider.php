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

namespace local_forumcare\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;

/**
 * Privacy subsystem implementation for local_forumcare.
 *
 * Reports are shared data (reporter, reportee, reviewer are all different
 * users), so deleting a user's data anonymises that user's own identifying
 * fields on the row rather than deleting the row outright, since the row
 * is still needed as moderation history for the other parties involved.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns meta data about this system.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_forumcare_report', [
            'reporterid' => 'privacy:metadata:local_forumcare_report:reporterid',
            'reasonid' => 'privacy:metadata:local_forumcare_report:reasonid',
            'comment' => 'privacy:metadata:local_forumcare_report:comment',
            'outcome' => 'privacy:metadata:local_forumcare_report:outcome',
            'reviewedby' => 'privacy:metadata:local_forumcare_report:reviewedby',
        ], 'privacy:metadata:local_forumcare_report');

        $collection->add_database_table('local_forumcare_hidden', [
            'originalmessage' => 'privacy:metadata:local_forumcare_hidden:originalmessage',
            'hiddenby' => 'privacy:metadata:local_forumcare_hidden:hiddenby',
        ], 'privacy:metadata:local_forumcare_hidden');

        return $collection;
    }

    /**
     * Get the list of module contexts where a user has reported a post,
     * had their own post reported, or reviewed a report.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {local_forumcare_report} r
                  JOIN {forum_posts} p ON p.id = r.postid
                  JOIN {forum_discussions} d ON d.id = r.discussionid
                  JOIN {course_modules} cm ON cm.instance = r.forumid AND cm.course = d.course
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE r.reporterid = :userid1 OR p.userid = :userid2 OR r.reviewedby = :userid3";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        // Hidden-post backups: the user may be the moderator who hid a post
        // (hiddenby) or the author whose content is stored (post.userid).
        $hiddensql = "SELECT ctx.id
                        FROM {local_forumcare_hidden} h
                        JOIN {forum_posts} p ON p.id = h.postid
                        JOIN {forum_discussions} d ON d.id = p.discussion
                        JOIN {course_modules} cm ON cm.instance = d.forum AND cm.course = d.course
                        JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
                        JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                       WHERE h.hiddenby = :userid1 OR p.userid = :userid2";
        $contextlist->add_from_sql($hiddensql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid1' => $userid,
            'userid2' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Export all user data for the given approved contextlist.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('forum', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $reports = $DB->get_records('local_forumcare_report', ['forumid' => $cm->instance]);
            $exportdata = [];
            foreach ($reports as $report) {
                if ($report->reporterid != $userid && $report->reviewedby != $userid) {
                    // Only export rows where this user is reporter or reviewer here;
                    // reports against the user's own posts are exported separately below.
                    continue;
                }
                $exportdata[] = [
                    'postid' => $report->postid,
                    'role' => $report->reporterid == $userid ? 'reporter' : 'reviewer',
                    'comment' => $report->comment,
                    'status' => $report->status,
                    'outcome' => $report->outcome,
                    'timecreated' => \core_privacy\local\request\transform::datetime($report->timecreated),
                ];
            }

            // Hidden-post backups for this forum where the user is the author of
            // the hidden post or the moderator who hid it. The author gets their
            // original content back (the live post now shows a placeholder); the
            // moderator gets a record of the action without the author's content.
            $hiddensql = "SELECT h.*, p.userid AS authorid
                            FROM {local_forumcare_hidden} h
                            JOIN {forum_posts} p ON p.id = h.postid
                            JOIN {forum_discussions} d ON d.id = p.discussion
                           WHERE d.forum = :forumid AND (h.hiddenby = :userid1 OR p.userid = :userid2)";
            $hiddenrows = $DB->get_records_sql($hiddensql, [
                'forumid' => $cm->instance,
                'userid1' => $userid,
                'userid2' => $userid,
            ]);
            $hiddendata = [];
            foreach ($hiddenrows as $hidden) {
                $isauthor = ($hidden->authorid == $userid);
                $entry = [
                    'postid' => $hidden->postid,
                    'role' => $isauthor ? 'author' : 'moderator',
                    'timehidden' => \core_privacy\local\request\transform::datetime($hidden->timehidden),
                ];
                if ($isauthor) {
                    $entry['originalmessage'] = $hidden->originalmessage;
                }
                $hiddendata[] = $entry;
            }

            if (!empty($exportdata) || !empty($hiddendata)) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_forumcare')],
                    (object) ['reports' => $exportdata, 'hiddenposts' => $hiddendata]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('forum', $context->instanceid);
        if (!$cm) {
            return;
        }

        $DB->delete_records('local_forumcare_report', ['forumid' => $cm->instance]);

        // Remove hidden-post backups belonging to this forum's posts.
        $DB->delete_records_select(
            'local_forumcare_hidden',
            'postid IN (SELECT p.id
                          FROM {forum_posts} p
                          JOIN {forum_discussions} d ON d.id = p.discussion
                         WHERE d.forum = :forumid)',
            ['forumid' => $cm->instance]
        );
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * Reports are shared data, so this anonymises the user's own identifying
     * fields rather than deleting rows that other users still need as
     * moderation history.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('forum', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $DB->set_field('local_forumcare_report', 'reporterid', 0, [
                'forumid' => $cm->instance,
                'reporterid' => $userid,
            ]);
            $DB->set_field('local_forumcare_report', 'reviewedby', 0, [
                'forumid' => $cm->instance,
                'reviewedby' => $userid,
            ]);

            // Anonymise the moderator id on hidden-post backups for this forum.
            $DB->set_field_select(
                'local_forumcare_hidden',
                'hiddenby',
                0,
                'hiddenby = :userid AND postid IN (SELECT p.id
                                                     FROM {forum_posts} p
                                                     JOIN {forum_discussions} d ON d.id = p.discussion
                                                    WHERE d.forum = :forumid)',
                ['userid' => $userid, 'forumid' => $cm->instance]
            );
        }
    }
}
