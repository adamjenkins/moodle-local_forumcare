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
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
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
    \core_privacy\local\request\core_userlist_provider,
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

            self::delete_users_data_in_forum((int) $cm->instance, [(int) $userid]);
        }
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('forum', $context->instanceid);
        if (!$cm) {
            return;
        }
        $params = ['forumid' => $cm->instance];

        // Reporters, reviewers and the authors of reported posts.
        $userlist->add_from_sql(
            'reporterid',
            "SELECT reporterid FROM {local_forumcare_report} WHERE forumid = :forumid AND reporterid > 0",
            $params
        );
        $userlist->add_from_sql(
            'reviewedby',
            "SELECT reviewedby FROM {local_forumcare_report} WHERE forumid = :forumid AND reviewedby > 0",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT p.userid
               FROM {local_forumcare_report} r
               JOIN {forum_posts} p ON p.id = r.postid
              WHERE r.forumid = :forumid",
            $params
        );

        // Moderators who hid a post and the authors whose content is backed up.
        $hiddenfrom = "FROM {local_forumcare_hidden} h
                       JOIN {forum_posts} p ON p.id = h.postid
                       JOIN {forum_discussions} d ON d.id = p.discussion
                      WHERE d.forum = :forumid";
        $userlist->add_from_sql('hiddenby', "SELECT h.hiddenby $hiddenfrom AND h.hiddenby > 0", $params);
        $userlist->add_from_sql('userid', "SELECT p.userid $hiddenfrom", $params);
    }

    /**
     * Delete data for multiple users within a single context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('forum', $context->instanceid);
        if (!$cm) {
            return;
        }
        $userids = array_map('intval', $userlist->get_userids());
        if ($userids) {
            self::delete_users_data_in_forum((int) $cm->instance, $userids);
        }
    }

    /**
     * Remove the given users' personal data from one forum's forum care rows.
     *
     * Reports are shared moderation history, so the users' ids are anonymised
     * and a reporter's own free-text comment is blanked. A hidden-post backup
     * holds the post author's original content: it is deleted when its author
     * is erased (core forum keeps the post row but blanks it, and the backup
     * must not survive or be written back by "Mark as OK"). The moderator id
     * on other backups is anonymised.
     *
     * @param int $forumid
     * @param int[] $userids
     * @return void
     */
    protected static function delete_users_data_in_forum(int $forumid, array $userids): void {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = $inparams + ['forumid' => $forumid];

        // Blank the comment first: the reporterid condition stops matching once it is zeroed.
        $DB->set_field_select(
            'local_forumcare_report',
            'comment',
            '',
            "forumid = :forumid AND reporterid $insql",
            $params
        );
        $DB->set_field_select(
            'local_forumcare_report',
            'reporterid',
            0,
            "forumid = :forumid AND reporterid $insql",
            $params
        );
        $DB->set_field_select(
            'local_forumcare_report',
            'reviewedby',
            0,
            "forumid = :forumid AND reviewedby $insql",
            $params
        );

        $forumposts = "SELECT p.id
                         FROM {forum_posts} p
                         JOIN {forum_discussions} d ON d.id = p.discussion
                        WHERE d.forum = :forumid";

        // The erased authors' own content.
        [$authorsql, $authorparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'author');
        $DB->delete_records_select(
            'local_forumcare_hidden',
            "postid IN ($forumposts AND p.userid $authorsql)",
            ['forumid' => $forumid] + $authorparams
        );

        $DB->set_field_select(
            'local_forumcare_hidden',
            'hiddenby',
            0,
            "hiddenby $insql AND postid IN ($forumposts)",
            $params
        );
    }
}
