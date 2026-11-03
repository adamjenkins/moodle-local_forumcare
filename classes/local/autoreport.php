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
 * AutoReport: report forum posts that contain a reason's telltale strings.
 *
 * Work is split by post id. The scheduled task keeps a watermark (the highest
 * post id already handed out) and queues one ad hoc task per block of posts
 * above it, so every post is scanned once, and a site that switches AutoReport
 * on with a large backlog drains it a bounded number of blocks per cron run.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autoreport {
    /** @var int The reporter id recorded on automatic reports: core's no-reply user. */
    const REPORTER_ID = \core_user::NOREPLY_USER;

    /** @var string Config key holding the highest post id already queued for scanning. */
    const WATERMARK = 'autoreport_lastpostid';

    /** @var int Posts per ad hoc task. */
    const BLOCK_SIZE = 500;

    /** @var int Most telltale strings one reason may hold (bounds the work per post). */
    const MAX_STRINGS = 200;

    /** @var int Most ad hoc tasks queued per scheduled run (bounds the backlog drain). */
    const MAX_BLOCKS_PER_RUN = 20;

    /**
     * Split a textarea of telltale strings into a clean list: one per line,
     * trimmed, whitespace collapsed, blanks and case-insensitive repeats removed.
     *
     * @param string $text
     * @return string[]
     */
    public static function parse_strings(string $text): array {
        $strings = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            $key = \core_text::strtolower($line);
            if (!isset($strings[$key])) {
                $strings[$key] = $line;
            }
        }
        return array_values($strings);
    }

    /**
     * Reduce a post to lower-case plain text with single spaces, for matching.
     *
     * @param string $subject
     * @param string $message
     * @return string
     */
    public static function normalise_text(string $subject, string $message): string {
        // Break tags into spaces first so "<p>As an</p><p>AI</p>" still reads "As an AI".
        $text = $subject . ' ' . preg_replace('/<[^>]*>/', ' ', $message);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return \core_text::strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * The telltale strings of a reason found in a post's normalised text.
     *
     * @param string $text Output of normalise_text().
     * @param string[] $strings Output of parse_strings().
     * @return string[] The matching strings, as configured.
     */
    public static function find_matches(string $text, array $strings): array {
        $found = [];
        foreach ($strings as $string) {
            if (\core_text::strpos($text, \core_text::strtolower($string)) !== false) {
                $found[] = $string;
            }
        }
        return $found;
    }

    /**
     * Whether any enabled reason anywhere has AutoReport switched on.
     *
     * @return bool
     */
    public static function is_in_use(): bool {
        global $DB;
        return $DB->record_exists('local_forumcare_reason', ['enabled' => 1, 'autoreport' => 1]);
    }

    /**
     * The enabled AutoReport reasons that apply to a course, with their parsed strings.
     *
     * @param int $courseid
     * @return array List of [reason record, string[]], in the reasons' display order.
     */
    public static function get_rules_for_course(int $courseid): array {
        $rules = [];
        foreach (helper::get_reasons_for_course($courseid) as $reason) {
            if (empty($reason->autoreport)) {
                continue;
            }
            // The form caps the list; the slice also bounds rows written another way.
            $strings = array_slice(self::parse_strings((string) $reason->autoreportstrings), 0, self::MAX_STRINGS);
            if ($strings) {
                $rules[] = [$reason, $strings];
            }
        }
        return $rules;
    }

    /**
     * Queue ad hoc scans for the posts written since the last run.
     *
     * Only posts older than the forum editing time are handed out, so a post is
     * scanned in its final form. While forum care or AutoReport is off nothing is
     * queued and the watermark stays put: switching it on later scans the backlog.
     *
     * @return int The number of ad hoc tasks queued.
     */
    public static function queue_new_posts(): int {
        global $CFG, $DB;

        if (!helper::is_site_enabled() || !self::is_in_use()) {
            return 0;
        }

        $lastid = (int) get_config('local_forumcare', self::WATERMARK);
        $cutoff = time() - (int) ($CFG->maxeditingtime ?? 1800);
        $maxid = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {forum_posts} WHERE id > :lastid AND created <= :cutoff',
            ['lastid' => $lastid, 'cutoff' => $cutoff]
        );

        $queued = 0;
        while ($maxid > $lastid && $queued < self::MAX_BLOCKS_PER_RUN) {
            // A block is the next BLOCK_SIZE posts, not BLOCK_SIZE ids: ids can be sparse.
            $nth = $DB->get_records_sql(
                'SELECT id FROM {forum_posts} WHERE id > :lastid AND id <= :maxid ORDER BY id',
                ['lastid' => $lastid, 'maxid' => $maxid],
                self::BLOCK_SIZE - 1,
                1
            );
            $toid = $nth ? (int) reset($nth)->id : $maxid;
            $task = new \local_forumcare\task\autoreport_scan_posts();
            $task->set_custom_data((object) ['fromid' => $lastid, 'toid' => $toid]);
            \core\task\manager::queue_adhoc_task($task, true);
            // Move the watermark block by block, so a failure part-way loses nothing.
            set_config(self::WATERMARK, $toid, 'local_forumcare');
            $lastid = $toid;
            $queued++;
        }
        return $queued;
    }

    /**
     * Scan the posts with fromid < id <= toid and report those that match.
     *
     * Skips posts in forums without forum care, deleted posts, posts forum care
     * has hidden, and posts AutoReport has already reported.
     *
     * @param int $fromid Exclusive.
     * @param int $toid Inclusive.
     * @return int The number of reports filed.
     */
    public static function scan_range(int $fromid, int $toid): int {
        global $DB;

        if (!helper::is_site_enabled()) {
            return 0;
        }

        $sql = "SELECT p.id, p.discussion, p.userid, p.subject, p.message, d.course, d.forum
                  FROM {forum_posts} p
                  JOIN {forum_discussions} d ON d.id = p.discussion
                  JOIN {local_forumcare_forum} fc ON fc.forumid = d.forum AND fc.enabled = 1
             LEFT JOIN {local_forumcare_hidden} h ON h.postid = p.id
             LEFT JOIN {local_forumcare_report} r ON r.postid = p.id AND r.reporterid = :reporterid
                 WHERE p.id > :fromid AND p.id <= :toid AND p.deleted = 0
                       AND h.id IS NULL AND r.id IS NULL
              ORDER BY p.id";
        $rs = $DB->get_recordset_sql($sql, ['reporterid' => self::REPORTER_ID, 'fromid' => $fromid, 'toid' => $toid]);

        $rulesbycourse = [];
        $cmbyforum = [];
        $tofile = [];
        foreach ($rs as $post) {
            $courseid = (int) $post->course;
            if (!array_key_exists($courseid, $rulesbycourse)) {
                $rulesbycourse[$courseid] = self::get_rules_for_course($courseid);
            }
            if (!$rulesbycourse[$courseid]) {
                continue;
            }
            $forumid = (int) $post->forum;
            if (!array_key_exists($forumid, $cmbyforum)) {
                $cmbyforum[$forumid] = get_coursemodule_from_instance('forum', $forumid, $courseid);
            }
            $cm = $cmbyforum[$forumid];
            if (!$cm) {
                // A forum without its course module (e.g. mid-deletion): nothing to report against.
                continue;
            }
            $text = self::normalise_text((string) $post->subject, (string) $post->message);
            foreach ($rulesbycourse[$courseid] as [$reason, $strings]) {
                $matches = self::find_matches($text, $strings);
                if ($matches) {
                    unset($post->subject, $post->message);
                    $tofile[] = [$post, $cm, $reason, $matches];
                    // One automatic report per post: the first matching reason wins.
                    break;
                }
            }
        }
        $rs->close();

        // File after the recordset is closed: each report runs in its own transaction.
        foreach ($tofile as [$post, $cm, $reason, $matches]) {
            self::report_post($post, $cm, $reason, $matches);
        }
        return count($tofile);
    }

    /**
     * File an automatic report and apply the usual thresholds.
     *
     * The report, its event and the thresholds' effects commit together: if any
     * step fails, nothing is kept, so a retry of the block reports the post again.
     *
     * @param \stdClass $post Row from scan_range() (post fields plus course and forum).
     * @param \stdClass|\cm_info $cm The forum's course module.
     * @param \stdClass $reason
     * @param string[] $matches
     * @return void
     */
    protected static function report_post(\stdClass $post, $cm, \stdClass $reason, array $matches): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $report = (object) [
            'postid' => $post->id,
            'discussionid' => $post->discussion,
            'forumid' => $post->forum,
            'courseid' => $post->course,
            'reporterid' => self::REPORTER_ID,
            'reasonid' => $reason->id,
            'comment' => get_string('autoreportcomment', 'local_forumcare', implode(', ', $matches)),
            'status' => 'pending',
            'timecreated' => time(),
        ];
        $reportid = $DB->insert_record('local_forumcare_report', $report);

        $event = \local_forumcare\event\post_reported::create([
            'context' => \context_module::instance($cm->id),
            'userid' => 0,
            'objectid' => $reportid,
            'other' => ['postid' => (int) $post->id, 'autoreport' => 1],
        ]);
        $event->trigger();

        $discussion = (object) ['forum' => $post->forum, 'course' => $post->course];
        helper::enforce_thresholds((object) ['id' => $post->id, 'userid' => $post->userid], $discussion);

        $transaction->allow_commit();
    }
}
