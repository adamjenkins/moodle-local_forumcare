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

namespace local_forumcare;

use local_forumcare\local\helper;

/**
 * Tests for moderator actions applied via helper::apply_moderation().
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\local\helper
 */
final class moderate_report_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $forum;

    /** @var int */
    private $reasonid;

    /**
     * Set up a course, forum and one report reason.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        helper::set_forum_enabled($this->forum->id, true);

        $this->reasonid = $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Offensive language',
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Create a post, a report against it, and return both.
     *
     * @return array [post, report]
     */
    private function create_post_and_report(): array {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);

        $reportid = $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $post->id,
            'discussionid' => $discussion->id,
            'forumid' => $this->forum->id,
            'courseid' => $this->course->id,
            'reporterid' => $reporter->id,
            'reasonid' => $this->reasonid,
            'comment' => '',
            'status' => 'pending',
            'timecreated' => time(),
        ]);
        $report = $DB->get_record('local_forumcare_report', ['id' => $reportid], '*', MUST_EXIST);

        return [$post, $report, $author];
    }

    /**
     * Marking a hidden post OK restores its original content.
     */
    public function test_mark_ok_restores_hidden_content(): void {
        global $DB;

        [$post, $report] = $this->create_post_and_report();
        $originalmessage = $post->message;

        helper::hide_post($post->id, 0, true);
        $hidden = $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST);
        $this->assertNotEquals($originalmessage, $hidden->message);

        $teacher = $this->getDataGenerator()->create_user();
        helper::apply_moderation($report->id, 'ok', $teacher->id);

        $restored = $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST);
        $this->assertEquals($originalmessage, $restored->message);

        $updatedreport = $DB->get_record('local_forumcare_report', ['id' => $report->id], '*', MUST_EXIST);
        $this->assertEquals('reviewed', $updatedreport->status);
        $this->assertEquals('ok', $updatedreport->outcome);
        $this->assertFalse($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));
    }

    /**
     * Suspending a user in a course sets their course enrolment to suspended.
     */
    public function test_suspend_course_action(): void {
        global $DB;

        [, $report, $author] = $this->create_post_and_report();
        $teacher = $this->getDataGenerator()->create_user();

        helper::apply_moderation($report->id, 'suspend_course', $teacher->id);

        $enrolment = $DB->get_record_sql(
            "SELECT ue.* FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $this->course->id, 'userid' => $author->id]
        );
        $this->assertEquals(ENROL_USER_SUSPENDED, $enrolment->status);
    }

    /**
     * Marking a report frivolous sets the outcome and counts toward the reporter's block.
     */
    public function test_mark_frivolous(): void {
        global $DB;

        [, $report] = $this->create_post_and_report();
        $teacher = $this->getDataGenerator()->create_user();

        helper::apply_moderation($report->id, 'frivolous', $teacher->id);

        $updatedreport = $DB->get_record('local_forumcare_report', ['id' => $report->id], '*', MUST_EXIST);
        $this->assertEquals('frivolous', $updatedreport->outcome);
        $this->assertEquals(1, helper::count_frivolous_reports_by_user($report->reporterid));
    }

    /**
     * The manual "hide" action hides the post immediately without resolving
     * the report itself - it stays pending for further action.
     */
    public function test_manual_hide_action(): void {
        global $DB;

        [$post, $report] = $this->create_post_and_report();
        $originalmessage = $post->message;
        $teacher = $this->getDataGenerator()->create_user();

        helper::apply_moderation($report->id, 'hide', $teacher->id);

        $hidden = $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST);
        $this->assertNotEquals($originalmessage, $hidden->message);
        $this->assertStringContainsString(get_string('hiddenpostplaceholdermanual', 'local_forumcare'), $hidden->message);
        $this->assertStringContainsString('alert-info', $hidden->message);
        $this->assertEquals(FORMAT_HTML, $hidden->messageformat);

        $updatedreport = $DB->get_record('local_forumcare_report', ['id' => $report->id], '*', MUST_EXIST);
        $this->assertEquals('pending', $updatedreport->status);
    }

    /**
     * Automatic threshold-triggered hides use a different placeholder
     * message than a moderator's manual hide action.
     */
    public function test_automatic_hide_uses_different_message_than_manual(): void {
        global $DB;

        [$post] = $this->create_post_and_report();

        helper::hide_post($post->id, 0, true);

        $hidden = $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST);
        $this->assertStringContainsString(get_string('hiddenpostplaceholder', 'local_forumcare'), $hidden->message);
        $this->assertStringNotContainsString(
            get_string('hiddenpostplaceholdermanual', 'local_forumcare'),
            $hidden->message
        );
    }

    /**
     * Undoing a review puts the report back into the pending queue and
     * clears its outcome, without reversing the original side effect.
     */
    public function test_undo_review_action(): void {
        global $DB;

        [, $report] = $this->create_post_and_report();
        $teacher = $this->getDataGenerator()->create_user();

        helper::apply_moderation($report->id, 'frivolous', $teacher->id);
        $reviewed = $DB->get_record('local_forumcare_report', ['id' => $report->id], '*', MUST_EXIST);
        $this->assertEquals('reviewed', $reviewed->status);
        $this->assertEquals('frivolous', $reviewed->outcome);

        helper::apply_moderation($report->id, 'undo', $teacher->id);

        $undone = $DB->get_record('local_forumcare_report', ['id' => $report->id], '*', MUST_EXIST);
        $this->assertEquals('pending', $undone->status);
        $this->assertNull($undone->outcome);
        $this->assertNull($undone->reviewedby);
        $this->assertNull($undone->timereviewed);

        // The frivolous mark is reversed too, since it's derived from outcome.
        $this->assertEquals(0, helper::count_frivolous_reports_by_user($report->reporterid));
    }
}
