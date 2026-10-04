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
 * Shared moderation logic used by both the automatic threshold checks
 * (triggered from report submission) and the manual moderator actions
 * on the review page, so the two paths can't drift apart.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** @var string Placeholder message shown when a post was auto-hidden by the report threshold. */
    const HIDDEN_PLACEHOLDER_LANGKEY = 'hiddenpostplaceholder';

    /** @var string Placeholder message shown when a moderator manually hid the post. */
    const HIDDEN_PLACEHOLDER_MANUAL_LANGKEY = 'hiddenpostplaceholdermanual';

    /** @var string[] Threshold names that can be overridden per-forum. */
    const PER_FORUM_THRESHOLDS = ['threshold_hide', 'threshold_suspend', 'threshold_frivolous'];

    /**
     * Whether forum care is enabled site-wide. This is a master switch:
     * when off, no forum can report regardless of its own opt-in setting.
     *
     * @return bool
     */
    public static function is_site_enabled(): bool {
        return (bool) get_config('local_forumcare', 'enabled');
    }

    /**
     * Whether forum care reporting is enabled for a given forum instance.
     * Disabled by default unless a teacher/manager has explicitly opted in.
     *
     * @param int $forumid
     * @return bool
     */
    public static function is_enabled_for_forum(int $forumid): bool {
        global $DB;
        return (bool) $DB->get_field('local_forumcare_forum', 'enabled', ['forumid' => $forumid]);
    }

    /**
     * Whether reporting can currently be used in a given forum: requires
     * both the site-wide switch and the per-forum opt-in to be enabled.
     *
     * @param int $forumid
     * @return bool
     */
    public static function can_report_in_forum(int $forumid): bool {
        return self::is_site_enabled() && self::is_enabled_for_forum($forumid);
    }

    /**
     * Enable or disable forum care reporting for a given forum instance.
     *
     * @param int $forumid
     * @param bool $enabled
     * @return void
     */
    public static function set_forum_enabled(int $forumid, bool $enabled): void {
        self::save_forum_record($forumid, ['enabled' => $enabled ? 1 : 0]);
    }

    /**
     * Set per-forum threshold overrides. A value of 0 disables that
     * protection for this forum; pass null to fall back to the site default.
     *
     * @param int $forumid
     * @param array $thresholds Map of threshold name (self::PER_FORUM_THRESHOLDS) to int|null.
     * @return void
     */
    public static function set_forum_thresholds(int $forumid, array $thresholds): void {
        $values = [];
        foreach (self::PER_FORUM_THRESHOLDS as $name) {
            if (array_key_exists($name, $thresholds)) {
                $values[$name] = $thresholds[$name] === null ? null : (int) $thresholds[$name];
            }
        }
        self::save_forum_record($forumid, $values);
    }

    /**
     * Resolve the effective threshold value for a forum: the per-forum
     * override if set, otherwise the site-wide default.
     *
     * @param int $forumid
     * @param string $name One of self::PER_FORUM_THRESHOLDS.
     * @return int
     */
    public static function get_threshold(int $forumid, string $name): int {
        global $DB;

        if (in_array($name, self::PER_FORUM_THRESHOLDS, true)) {
            $override = $DB->get_field('local_forumcare_forum', $name, ['forumid' => $forumid]);
            if ($override !== false && $override !== null) {
                return (int) $override;
            }
        }

        return (int) get_config('local_forumcare', $name);
    }

    /**
     * Insert or update the per-forum settings row with the given field values.
     *
     * @param int $forumid
     * @param array $values
     * @return void
     */
    private static function save_forum_record(int $forumid, array $values): void {
        global $DB;

        $record = $DB->get_record('local_forumcare_forum', ['forumid' => $forumid]);
        if ($record) {
            foreach ($values as $key => $value) {
                $record->$key = $value;
            }
            $record->timemodified = time();
            $DB->update_record('local_forumcare_forum', $record);
        } else {
            $record = (object) array_merge([
                'forumid' => $forumid,
                'enabled' => 0,
                'threshold_hide' => null,
                'threshold_suspend' => null,
                'threshold_frivolous' => null,
            ], $values);
            $record->timemodified = time();
            $DB->insert_record('local_forumcare_forum', $record);
        }
    }

    /**
     * Whether a course has opted to override the site-wide default report
     * reasons with its own course-specific list.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_course_overriding_reasons(int $courseid): bool {
        global $DB;
        return (bool) $DB->get_field('local_forumcare_course', 'override_reasons', ['courseid' => $courseid]);
    }

    /**
     * Set whether a course overrides the site-wide default report reasons.
     *
     * @param int $courseid
     * @param bool $override
     * @return void
     */
    public static function set_course_override_reasons(int $courseid, bool $override): void {
        global $DB;

        $record = $DB->get_record('local_forumcare_course', ['courseid' => $courseid]);
        if ($record) {
            $record->override_reasons = $override ? 1 : 0;
            $record->timemodified = time();
            $DB->update_record('local_forumcare_course', $record);
        } else {
            $DB->insert_record('local_forumcare_course', (object) [
                'courseid' => $courseid,
                'override_reasons' => $override ? 1 : 0,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Get the enabled report reasons that should be offered to a reporter in
     * a given course: the course's own reasons if it overrides the site
     * defaults, otherwise the site-wide default reasons.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function get_reasons_for_course(int $courseid): array {
        global $DB;

        if (self::is_course_overriding_reasons($courseid)) {
            return array_values($DB->get_records(
                'local_forumcare_reason',
                ['courseid' => $courseid, 'enabled' => 1],
                'sortorder ASC, id ASC'
            ));
        }

        $sql = "SELECT * FROM {local_forumcare_reason} WHERE courseid IS NULL AND enabled = 1
                 ORDER BY sortorder ASC, id ASC";
        return array_values($DB->get_records_sql($sql));
    }

    /**
     * Whether any report references the given reason. Used to prevent a reason
     * from being hard-deleted while reports still point at it, which would drop
     * those reports from the review queue's inner join to the reason table.
     *
     * @param int $reasonid
     * @return bool
     */
    public static function reason_has_reports(int $reasonid): bool {
        global $DB;
        return $DB->record_exists('local_forumcare_report', ['reasonid' => $reasonid]);
    }

    /**
     * Count open (status=pending) reports filed against a single post.
     *
     * @param int $postid
     * @return int
     */
    public static function count_open_reports_for_post(int $postid): int {
        global $DB;
        return $DB->count_records('local_forumcare_report', ['postid' => $postid, 'status' => 'pending']);
    }

    /**
     * Count the distinct reporters who have open reports against a user's posts
     * within a single course. Counting reporters (not raw reports) means the
     * suspend threshold reflects how many different people complained, so one
     * person reporting many of the author's posts cannot reach it alone.
     *
     * @param int $userid The post author (reportee).
     * @param int $courseid
     * @return int
     */
    public static function count_open_reports_against_user_in_course(int $userid, int $courseid): int {
        global $DB;
        $sql = "SELECT COUNT(DISTINCT r.reporterid)
                  FROM {local_forumcare_report} r
                  JOIN {forum_posts} p ON p.id = r.postid
                 WHERE p.userid = :userid AND r.courseid = :courseid AND r.status = :status";
        return (int) $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => 'pending',
        ]);
    }

    /**
     * Count the distinct reporters who have open reports against a user's posts
     * across the whole site. As with the per-course counter, counting reporters
     * rather than reports stops a single person from reaching the threshold alone.
     *
     * @param int $userid The post author (reportee).
     * @return int
     */
    public static function count_open_reports_against_user_sitewide(int $userid): int {
        global $DB;
        $sql = "SELECT COUNT(DISTINCT r.reporterid)
                  FROM {local_forumcare_report} r
                  JOIN {forum_posts} p ON p.id = r.postid
                 WHERE p.userid = :userid AND r.status = :status";
        return (int) $DB->count_records_sql($sql, [
            'userid' => $userid,
            'status' => 'pending',
        ]);
    }

    /**
     * Count reports a user has filed that have been marked frivolous.
     *
     * @param int $reporterid
     * @return int
     */
    public static function count_frivolous_reports_by_user(int $reporterid): int {
        global $DB;
        return $DB->count_records('local_forumcare_report', [
            'reporterid' => $reporterid,
            'outcome' => 'frivolous',
        ]);
    }

    /**
     * Whether a user is currently blocked from submitting new reports
     * because they have reached the configured frivolous-report threshold.
     *
     * @param int $reporterid
     * @param int $forumid The forum they're trying to report in, used to resolve
     *                      a per-forum threshold override if one is set.
     * @return bool
     */
    public static function is_reporter_blocked(int $reporterid, int $forumid): bool {
        $threshold = self::get_threshold($forumid, 'threshold_frivolous');
        if ($threshold <= 0) {
            return false;
        }
        return self::count_frivolous_reports_by_user($reporterid) >= $threshold;
    }

    /**
     * Hide a post: back up its original content and overwrite it with a placeholder.
     * No-op if the post is already hidden.
     *
     * @param int $postid
     * @param int $hiddenby 0 for automatic/system, otherwise the acting userid.
     * @param bool $automatic
     * @return void
     */
    public static function hide_post(int $postid, int $hiddenby, bool $automatic): void {
        global $DB;

        if ($DB->record_exists('local_forumcare_hidden', ['postid' => $postid])) {
            return;
        }

        $post = $DB->get_record('forum_posts', ['id' => $postid], '*', MUST_EXIST);

        $langkey = $automatic ? self::HIDDEN_PLACEHOLDER_LANGKEY : self::HIDDEN_PLACEHOLDER_MANUAL_LANGKEY;
        $placeholder = self::render_placeholder($langkey);

        $backup = new \stdClass();
        $backup->postid = $postid;
        $backup->originalmessage = $post->message;
        $backup->originalmessageformat = $post->messageformat;
        $backup->originalmessagetrust = $post->messagetrust;
        $backup->hiddenby = $hiddenby;
        $backup->timehidden = time();
        $backup->placeholder = $placeholder;
        $backupid = $DB->insert_record('local_forumcare_hidden', $backup);

        $update = new \stdClass();
        $update->id = $postid;
        $update->message = $placeholder;
        $update->messageformat = FORMAT_HTML;
        $update->messagetrust = 0;
        $DB->update_record('forum_posts', $update);

        $event = \local_forumcare\event\post_hidden::create([
            'context' => \context_system::instance(),
            'objectid' => $backupid,
            'other' => ['postid' => $postid, 'automatic' => $automatic],
        ]);
        $event->trigger();
    }

    /**
     * Restore a previously hidden post's original content.
     *
     * @param int $postid
     * @return void
     */
    public static function unhide_post(int $postid): void {
        global $DB;

        $backup = $DB->get_record('local_forumcare_hidden', ['postid' => $postid]);
        if (!$backup) {
            return;
        }

        // Core has deleted (or privacy-erased) the post: its content must not
        // come back, so drop the backup instead of writing it into the post.
        if ($DB->get_field('forum_posts', 'deleted', ['id' => $postid])) {
            $DB->delete_records('local_forumcare_hidden', ['postid' => $postid]);
            return;
        }

        $update = new \stdClass();
        $update->id = $postid;
        $update->message = $backup->originalmessage;
        $update->messageformat = $backup->originalmessageformat;
        $update->messagetrust = $backup->originalmessagetrust;
        $DB->update_record('forum_posts', $update);

        $DB->delete_records('local_forumcare_hidden', ['postid' => $postid]);
    }

    /**
     * The placeholder HTML written over a hidden post's message.
     *
     * @param string $langkey One of the HIDDEN_PLACEHOLDER_* lang keys.
     * @return string
     */
    protected static function render_placeholder(string $langkey): string {
        return \html_writer::div(get_string($langkey, 'local_forumcare'), 'alert alert-info');
    }

    /**
     * Reduce a post message to whitespace-normalised plain text, so an editor
     * round trip of the same placeholder (reformatted markup) compares equal.
     *
     * @param string $message
     * @return string
     */
    protected static function normalise_message_text(string $message): string {
        return trim(preg_replace('/\s+/u', ' ', html_to_text($message, 0, false)));
    }

    /**
     * Whether a saved post message is (an editor round trip of) the placeholder
     * this plugin wrote over the hidden post.
     *
     * The message is compared with the exact placeholder stored when it was
     * written, not with the lang string re-derived now: the post may have been
     * hidden in another user's language, or the string may have been customised
     * since. Rows hidden before that text was stored (null) fall back to the
     * lang strings in the current and the English language.
     *
     * @param string $message The saved post message.
     * @param string|null $storedplaceholder The placeholder recorded in the backup row.
     * @return bool
     */
    protected static function is_placeholder_message(string $message, ?string $storedplaceholder): bool {
        $text = self::normalise_message_text($message);
        if ($storedplaceholder !== null && $storedplaceholder !== '') {
            if ($text === self::normalise_message_text($storedplaceholder)) {
                return true;
            }
        }
        $manager = get_string_manager();
        foreach ([self::HIDDEN_PLACEHOLDER_LANGKEY, self::HIDDEN_PLACEHOLDER_MANUAL_LANGKEY] as $key) {
            if (
                $text === trim(get_string($key, 'local_forumcare'))
                    || $text === trim($manager->get_string($key, 'local_forumcare', null, 'en'))
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep a hidden post hidden after its content was edited through mod_forum.
     *
     * The author (inside the edit window) or anyone with mod/forum:editanypost
     * can save over the placeholder. The newly saved text becomes the backed-up
     * original, so "Mark as OK" later restores the latest version rather than a
     * stale one, and the placeholder is put back on the live post. An edit that
     * leaves the placeholder itself unchanged (e.g. a subject-only edit) keeps
     * the existing backup.
     *
     * @param int $postid
     * @return void
     */
    public static function rehide_edited_post(int $postid): void {
        global $DB;

        $backup = $DB->get_record('local_forumcare_hidden', ['postid' => $postid]);
        if (!$backup) {
            return;
        }
        $post = $DB->get_record('forum_posts', ['id' => $postid]);
        if (!$post || !empty($post->deleted)) {
            return;
        }

        if (!self::is_placeholder_message((string) $post->message, $backup->placeholder ?? null)) {
            $backup->originalmessage = $post->message;
            $backup->originalmessageformat = $post->messageformat;
            $backup->originalmessagetrust = $post->messagetrust;
        }

        // Record the placeholder actually written, so the next edit is
        // recognised whatever the editor's language or later string changes.
        $langkey = empty($backup->hiddenby) ? self::HIDDEN_PLACEHOLDER_LANGKEY : self::HIDDEN_PLACEHOLDER_MANUAL_LANGKEY;
        $placeholder = self::render_placeholder($langkey);
        $backup->placeholder = $placeholder;
        $DB->update_record('local_forumcare_hidden', $backup);

        $DB->update_record('forum_posts', (object) [
            'id' => $postid,
            'message' => $placeholder,
            'messageformat' => FORMAT_HTML,
            'messagetrust' => 0,
        ]);
    }

    /**
     * Whether a user can see a forum post, using mod_forum's own rules (groups,
     * private replies, deleted posts, timed discussions).
     *
     * @param \stdClass $post forum_posts record
     * @param \stdClass $discussion forum_discussions record
     * @param \stdClass $forum forum record
     * @param \stdClass|\cm_info $cm
     * @param \stdClass $user
     * @return bool
     */
    public static function user_can_see_post(\stdClass $post, \stdClass $discussion, \stdClass $forum, $cm, \stdClass $user): bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        if (!empty($post->deleted)) {
            return false;
        }
        return (bool) forum_user_can_see_post($forum, $discussion, $post, $user, $cm);
    }

    /**
     * Whether a user must never be suspended by this plugin in a course: site
     * admins, and anyone who moderates the course (reviews reports or can edit
     * the course). This stops students using the thresholds, or a non-editing
     * teacher using the manual action, to lock out a higher role.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function is_protected_from_suspension(int $userid, int $courseid): bool {
        if (is_siteadmin($userid)) {
            return true;
        }
        $coursecontext = \context_course::instance($courseid);
        return has_capability('local/forumcare:reviewreports', $coursecontext, $userid)
            || has_capability('moodle/course:update', $coursecontext, $userid)
            || has_capability('local/forumcare:suspendsitewide', \context_system::instance(), $userid);
    }

    /**
     * Suspend a user's enrolment in a single course, across all of their
     * enrolment instances in that course.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $actorid The user performing/triggering the suspension (0 = automatic).
     * @param bool $automatic
     * @return void
     */
    public static function suspend_course(int $userid, int $courseid, int $actorid, bool $automatic): void {
        global $DB;

        $sql = "SELECT e.*
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                 WHERE e.courseid = :courseid AND ue.userid = :userid AND ue.status = :active";
        $instances = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'active' => ENROL_USER_ACTIVE,
        ]);

        foreach ($instances as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if ($plugin) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }
        }

        $event = \local_forumcare\event\user_suspended::create([
            'context' => \context_system::instance(),
            'userid' => $actorid,
            'relateduserid' => $userid,
            'other' => ['scope' => 'course', 'courseid' => $courseid, 'automatic' => $automatic],
        ]);
        $event->trigger();
    }

    /** @var string[] Valid moderator action values for apply_moderation(). */
    const VALID_MODERATION_ACTIONS = ['ok', 'suspend_course', 'suspend_site', 'frivolous', 'hide', 'undo'];

    /**
     * Apply a moderator action to a report and fire the report_actioned event.
     *
     * Callers (the webservice and the review page) are responsible for their
     * own capability checks before calling this - including the extra
     * local/forumcare:suspendsitewide check for the suspend_site action.
     *
     * @param int $reportid
     * @param string $action One of self::VALID_MODERATION_ACTIONS.
     * @param int $actorid The reviewing user.
     * @return void
     */
    public static function apply_moderation(int $reportid, string $action, int $actorid): void {
        global $DB;

        if (!in_array($action, self::VALID_MODERATION_ACTIONS, true)) {
            throw new \coding_exception('Invalid forumcare moderation action: ' . $action);
        }

        $report = $DB->get_record('local_forumcare_report', ['id' => $reportid], '*', MUST_EXIST);
        $post = $DB->get_record('forum_posts', ['id' => $report->postid], '*', MUST_EXIST);

        if (
            ($action === 'suspend_course' || $action === 'suspend_site')
                && self::is_protected_from_suspension((int) $post->userid, (int) $report->courseid)
        ) {
            throw new \moodle_exception('errorcannotsuspendprivileged', 'local_forumcare');
        }

        switch ($action) {
            case 'ok':
                self::unhide_post($report->postid);
                // Resolve every other open report against this same post too, not just the one actioned.
                $openreports = $DB->get_records('local_forumcare_report', [
                    'postid' => $report->postid,
                    'status' => 'pending',
                ]);
                foreach ($openreports as $openreport) {
                    $openreport->status = 'reviewed';
                    $openreport->outcome = 'ok';
                    $openreport->reviewedby = $actorid;
                    $openreport->timereviewed = time();
                    $DB->update_record('local_forumcare_report', $openreport);
                }
                break;

            case 'suspend_course':
                self::suspend_course($post->userid, $report->courseid, $actorid, false);
                $report->status = 'reviewed';
                $report->reviewedby = $actorid;
                $report->timereviewed = time();
                $DB->update_record('local_forumcare_report', $report);
                break;

            case 'suspend_site':
                self::suspend_sitewide($post->userid, $actorid, false);
                $report->status = 'reviewed';
                $report->reviewedby = $actorid;
                $report->timereviewed = time();
                $DB->update_record('local_forumcare_report', $report);
                break;

            case 'frivolous':
                $report->outcome = 'frivolous';
                $report->status = 'reviewed';
                $report->reviewedby = $actorid;
                $report->timereviewed = time();
                $DB->update_record('local_forumcare_report', $report);
                break;

            case 'hide':
                // Manual hide: lets a teacher hide a post immediately without
                // waiting for the auto-hide threshold to be reached. Does not
                // resolve the report itself - it stays pending for further action.
                self::hide_post($report->postid, $actorid, false);
                break;

            case 'undo':
                // Put a reviewed report back into the pending queue. This does not
                // reverse any side effect already taken (e.g. an enrolment
                // suspension), only the report's own status/outcome.
                $report->status = 'pending';
                $report->outcome = null;
                $report->reviewedby = null;
                $report->timereviewed = null;
                $DB->update_record('local_forumcare_report', $report);
                break;
        }

        $event = \local_forumcare\event\report_actioned::create([
            'context' => \context_course::instance($report->courseid),
            'userid' => $actorid,
            'objectid' => $report->id,
            'other' => ['reportid' => $report->id, 'action' => $action],
        ]);
        $event->trigger();
    }

    /**
     * Suspend a user's account site-wide.
     *
     * @param int $userid
     * @param int $actorid The user performing/triggering the suspension (0 = automatic).
     * @param bool $automatic
     * @return void
     */
    public static function suspend_sitewide(int $userid, int $actorid, bool $automatic): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $user->suspended = 1;
        if (method_exists(\core\user::class, 'update_user')) {
            // Moodle 5.3+: user_update_user() is deprecated (MDL-82650).
            \core\user::update_user($user, false, false);
        } else {
            user_update_user($user, false, false);
        }

        $event = \local_forumcare\event\user_suspended::create([
            'context' => \context_system::instance(),
            'userid' => $actorid,
            'relateduserid' => $userid,
            'other' => ['scope' => 'site', 'automatic' => $automatic],
        ]);
        $event->trigger();
    }
}
