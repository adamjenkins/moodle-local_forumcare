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
 * Submit a report against a forum post.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_report extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'The forum post id being reported'),
            'reasonid' => new external_value(PARAM_INT, 'The selected report reason id'),
            'comment' => new external_value(PARAM_TEXT, 'Optional additional details', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Submit a report.
     *
     * @param int $postid
     * @param int $reasonid
     * @param string $comment
     * @return array
     */
    public static function execute(int $postid, int $reasonid, string $comment = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'postid' => $postid,
            'reasonid' => $reasonid,
            'comment' => $comment,
        ]);

        // A missing post and a post the user cannot see give the same error,
        // so this endpoint does not reveal which post ids exist.
        $post = $DB->get_record('forum_posts', ['id' => $params['postid']]);
        if (!$post) {
            throw new \moodle_exception('errorpostnotavailable', 'local_forumcare');
        }
        $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $discussion->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/forumcare:report', $context);

        // Only posts the user can actually see may be reported: not a post in
        // another separate group, a private reply to someone else, or a deleted post.
        if (!helper::user_can_see_post($post, $discussion, $forum, $cm, $USER)) {
            throw new \moodle_exception('errorpostnotavailable', 'local_forumcare');
        }

        if (!helper::can_report_in_forum($forum->id)) {
            throw new \moodle_exception('errorforumcaredisabled', 'local_forumcare');
        }

        // A user cannot report their own post. The reporting UI already hides the
        // link on own posts; this enforces the same rule against direct calls.
        if ((int) $post->userid === (int) $USER->id) {
            throw new \moodle_exception('errorcannotreportownpost', 'local_forumcare');
        }

        $validreasonids = array_map('intval', array_column(helper::get_reasons_for_course($discussion->course), 'id'));
        if (!in_array($params['reasonid'], $validreasonids, true)) {
            throw new \moodle_exception('errorreasonrequired', 'local_forumcare');
        }

        // A user blocked for prior frivolous reports must see no difference in the
        // reporting UI: their report is recorded (for audit purposes) but marked
        // 'ignored' so it never counts toward any threshold and is excluded from
        // the teacher's pending review queue. No error is shown to the reporter.
        $isblocked = helper::is_reporter_blocked($USER->id, $forum->id);

        $report = new \stdClass();
        $report->postid = $params['postid'];
        $report->discussionid = $discussion->id;
        $report->forumid = $forum->id;
        $report->courseid = $discussion->course;
        $report->reporterid = $USER->id;
        $report->reasonid = $params['reasonid'];
        $report->comment = $params['comment'];
        $report->status = $isblocked ? 'ignored' : 'pending';
        $report->timecreated = time();

        // The (postid, reporterid) index is not unique, because a privacy erasure
        // anonymises every erased reporter to 0. A lock stops a double submit from
        // filing two reports by the same user between the check and the insert.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_forumcare_report');
        $lock = $lockfactory->get_lock('post' . $params['postid'] . 'user' . $USER->id, 5);
        if (!$lock) {
            throw new \moodle_exception('erroralreadyreported', 'local_forumcare');
        }
        try {
            if ($DB->record_exists('local_forumcare_report', ['postid' => $params['postid'], 'reporterid' => $USER->id])) {
                throw new \moodle_exception('erroralreadyreported', 'local_forumcare');
            }
            $reportid = $DB->insert_record('local_forumcare_report', $report);
        } finally {
            $lock->release();
        }

        $event = \local_forumcare\event\post_reported::create([
            'context' => $context,
            'objectid' => $reportid,
            'other' => ['postid' => $params['postid']],
        ]);
        $event->trigger();

        if (!$isblocked) {
            helper::enforce_thresholds($post, $discussion);
        }

        return ['success' => true];
    }

    /**
     * Describe the return structure of the external service.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the report was submitted successfully'),
        ]);
    }
}
