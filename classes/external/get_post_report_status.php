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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * For a batch of forum posts, tell the client whether each one is the
 * current user's own post, and whether they have already reported it - so
 * the report link can be hidden or replaced with "Post reported" without a
 * round trip per post.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_post_report_status extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postids' => new external_multiple_structure(new external_value(PARAM_INT, 'A forum post id')),
        ]);
    }

    /**
     * Return per-post status for the given posts. Posts the user has no
     * access to are silently omitted rather than causing the whole batch
     * to fail.
     *
     * @param int[] $postids
     * @return array
     */
    public static function execute(array $postids): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['postids' => $postids]);

        if (empty($params['postids'])) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($params['postids'], SQL_PARAMS_NAMED);
        $posts = $DB->get_records_sql(
            "SELECT p.id, p.userid, d.forum, d.course
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE p.id $insql",
            $inparams
        );

        $result = [];
        foreach ($posts as $post) {
            try {
                $cm = get_coursemodule_from_instance('forum', $post->forum, $post->course, false, MUST_EXIST);
                $context = \context_module::instance($cm->id);
                self::validate_context($context);
            } catch (\Exception $e) {
                continue;
            }

            if (!has_capability('local/forumcare:report', $context)) {
                continue;
            }

            $result[] = [
                'postid' => (int) $post->id,
                'isown' => (int) $post->userid === (int) $USER->id,
                'reported' => $DB->record_exists('local_forumcare_report', [
                    'postid' => $post->id,
                    'reporterid' => $USER->id,
                ]),
            ];
        }

        return $result;
    }

    /**
     * Describe the return structure of the external service.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'postid' => new external_value(PARAM_INT, 'Post id'),
                'isown' => new external_value(PARAM_BOOL, 'Whether this is the current user\'s own post'),
                'reported' => new external_value(PARAM_BOOL, 'Whether the current user has already reported this post'),
            ])
        );
    }
}
