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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_forumcare\local\helper;

/**
 * Tests for the local_forumcare privacy provider.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\privacy\provider
 * @covers     \local_forumcare\local\helper::unhide_post
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $forum;

    /** @var int */
    private $reasonid;

    /**
     * Set up a course, forum and reason.
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
     * A reporter's submitted reports show up in their list of contexts and export.
     */
    public function test_get_contexts_for_userid_and_export(): void {
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

        $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $post->id,
            'discussionid' => $discussion->id,
            'forumid' => $this->forum->id,
            'courseid' => $this->course->id,
            'reporterid' => $reporter->id,
            'reasonid' => $this->reasonid,
            'comment' => 'Test comment',
            'status' => 'pending',
            'timecreated' => time(),
        ]);

        $contextlist = provider::get_contexts_for_userid($reporter->id);
        $this->assertCount(1, $contextlist->get_contexts());

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);
        $this->assertEquals($modcontext->id, $contextlist->get_contexts()[0]->id);

        $approvedcontextlist = new approved_contextlist($reporter, 'local_forumcare', [$modcontext->id]);
        provider::export_user_data($approvedcontextlist);

        $exportdata = writer::with_context($modcontext)->get_data([get_string('pluginname', 'local_forumcare')]);
        $this->assertNotEmpty($exportdata);
        $this->assertCount(1, $exportdata->reports);
    }

    /**
     * Create a post by the given author, hidden by the given moderator, and
     * return the post record. Leaves a local_forumcare_hidden backup row.
     *
     * @param \stdClass $author
     * @param int $hiddenby
     * @return \stdClass
     */
    private function create_hidden_post(\stdClass $author, int $hiddenby): \stdClass {
        global $DB;
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        helper::hide_post((int) $post->id, $hiddenby, false);
        return $post;
    }

    /**
     * A moderator who hid a post has that involvement discoverable and exported.
     */
    public function test_export_includes_moderator_hidden_action(): void {
        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $post = $this->create_hidden_post($author, (int) $moderator->id);

        $contextlist = provider::get_contexts_for_userid($moderator->id);
        $this->assertCount(1, $contextlist->get_contexts());

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);

        $approvedcontextlist = new approved_contextlist($moderator, 'local_forumcare', [$modcontext->id]);
        provider::export_user_data($approvedcontextlist);

        $exportdata = writer::with_context($modcontext)->get_data([get_string('pluginname', 'local_forumcare')]);
        $this->assertNotEmpty($exportdata->hiddenposts);
        $this->assertCount(1, $exportdata->hiddenposts);
    }

    /**
     * The author of a hidden post gets their original content back in an export,
     * since the live post now shows only a placeholder.
     */
    public function test_export_includes_author_original_content(): void {
        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $post = $this->create_hidden_post($author, (int) $moderator->id);

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);

        $approvedcontextlist = new approved_contextlist($author, 'local_forumcare', [$modcontext->id]);
        provider::export_user_data($approvedcontextlist);

        $exportdata = writer::with_context($modcontext)->get_data([get_string('pluginname', 'local_forumcare')]);
        $this->assertNotEmpty($exportdata->hiddenposts);
        $this->assertStringContainsString($post->message, $exportdata->hiddenposts[0]['originalmessage']);
    }

    /**
     * Deleting a moderator's data anonymises the hiddenby id on their hidden-post backups.
     */
    public function test_delete_data_for_user_anonymises_hiddenby(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $post = $this->create_hidden_post($author, (int) $moderator->id);

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);

        $approvedcontextlist = new approved_contextlist($moderator, 'local_forumcare', [$modcontext->id]);
        provider::delete_data_for_user($approvedcontextlist);

        $this->assertEquals(0, $DB->get_field('local_forumcare_hidden', 'hiddenby', ['postid' => $post->id]));
    }

    /**
     * Deleting all data in a forum context also removes its hidden-post backups.
     */
    public function test_delete_data_for_all_users_removes_hidden(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $post = $this->create_hidden_post($author, (int) $moderator->id);

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);

        provider::delete_data_for_all_users_in_context($modcontext);

        $this->assertFalse($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));
    }

    /**
     * Deleting a user's data anonymises their reporterid rather than removing the row.
     */
    public function test_delete_data_for_user_anonymises_reporter(): void {
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
            'comment' => 'I am B from tutorial group 3',
            'status' => 'pending',
            'timecreated' => time(),
        ]);

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);

        $approvedcontextlist = new approved_contextlist($reporter, 'local_forumcare', [$modcontext->id]);
        provider::delete_data_for_user($approvedcontextlist);

        $report = $DB->get_record('local_forumcare_report', ['id' => $reportid], '*', MUST_EXIST);
        $this->assertEquals(0, $report->reporterid);
        // The reporter's own free-text comment is their personal data too.
        $this->assertSame('', (string) $report->comment);
    }

    /**
     * Insert a report row against a post.
     *
     * @param \stdClass $post
     * @param int $reporterid
     * @param string $comment
     * @param int|null $reviewedby
     * @return int The report id.
     */
    private function insert_report(\stdClass $post, int $reporterid, string $comment = '', ?int $reviewedby = null): int {
        global $DB;
        return (int) $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $post->id,
            'discussionid' => $post->discussion,
            'forumid' => $this->forum->id,
            'courseid' => $this->course->id,
            'reporterid' => $reporterid,
            'reasonid' => $this->reasonid,
            'comment' => $comment,
            'status' => $reviewedby ? 'reviewed' : 'pending',
            'reviewedby' => $reviewedby,
            'timecreated' => time(),
        ]);
    }

    /**
     * The forum module context used throughout these tests.
     *
     * @return \context_module
     */
    private function forum_context(): \context_module {
        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        return \context_module::instance($cm->id);
    }

    /**
     * Mimic mod_forum's privacy erasure of a post: the row stays, its content is blanked.
     *
     * @param int $postid
     */
    private function forum_erase_post(int $postid): void {
        global $DB;
        $DB->update_record('forum_posts', (object) ['id' => $postid, 'subject' => '', 'message' => '', 'deleted' => 1]);
    }

    /**
     * Erasing a hidden post's author deletes the backup of their original content,
     * and a later "Mark as OK" cannot write the erased content back into forum_posts.
     */
    public function test_delete_data_for_user_removes_author_hidden_content(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $post = $this->create_hidden_post($author, (int) $moderator->id);
        $reportid = $this->insert_report($post, (int) $reporter->id);
        $this->assertTrue($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));

        // Core forum erases the post first (row kept, content blanked), then this plugin.
        $this->forum_erase_post((int) $post->id);
        provider::delete_data_for_user(new approved_contextlist($author, 'local_forumcare', [$this->forum_context()->id]));

        $this->assertFalse($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));

        helper::apply_moderation($reportid, 'ok', (int) $moderator->id);
        $this->assertSame('', $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
    }

    /**
     * "Mark as OK" never restores content into a post that core has marked deleted,
     * even if a backup row is still present.
     */
    public function test_mark_ok_does_not_restore_deleted_post(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $post = $this->create_hidden_post($author, (int) $moderator->id);
        $reportid = $this->insert_report($post, (int) $reporter->id);
        $this->forum_erase_post((int) $post->id);

        helper::apply_moderation($reportid, 'ok', (int) $moderator->id);

        $this->assertSame('', $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
        $this->assertFalse($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));
    }

    /**
     * get_users_in_context finds reporters, reviewers, reported authors, the
     * moderator who hid a post and the author of a hidden post.
     */
    public function test_get_users_in_context(): void {
        $author = $this->getDataGenerator()->create_user();
        $hiddenauthor = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $hider = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        global $DB;
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        $this->insert_report($post, (int) $reporter->id, 'x', (int) $reviewer->id);
        $this->create_hidden_post($hiddenauthor, (int) $hider->id);

        // A forum post with no forumcare involvement must not pull in its author.
        $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $stranger->id,
        ]);

        $context = $this->forum_context();
        $userlist = new \core_privacy\local\request\userlist($context, 'local_forumcare');
        provider::get_users_in_context($userlist);
        $ids = $userlist->get_userids();
        sort($ids);
        $expected = [$author->id, $hiddenauthor->id, $reporter->id, $reviewer->id, $hider->id];
        sort($expected);
        $this->assertEquals($expected, $ids);

        // Another context contributes nothing.
        $userlist = new \core_privacy\local\request\userlist(\context_course::instance($this->course->id), 'local_forumcare');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * delete_data_for_users applies the same rules as delete_data_for_user, only
     * to the approved users: reporter and reviewer ids and the reporter's comment
     * are anonymised, the hider id is cleared, and a hidden author's content is removed.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $otherreporter = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $hider = $this->getDataGenerator()->create_user();
        $hiddenauthor = $this->getDataGenerator()->create_user();

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        $report1 = $this->insert_report($post, (int) $reporter->id, 'my secret', (int) $reviewer->id);
        $report2 = $this->insert_report($post, (int) $otherreporter->id, 'keep me');
        $hiddenpost = $this->create_hidden_post($hiddenauthor, (int) $hider->id);
        $otherhidden = $this->create_hidden_post($author, (int) $hider->id);

        $context = $this->forum_context();
        $approved = new \core_privacy\local\request\approved_userlist(
            $context,
            'local_forumcare',
            [$reporter->id, $reviewer->id, $hider->id, $hiddenauthor->id]
        );
        provider::delete_data_for_users($approved);

        $r1 = $DB->get_record('local_forumcare_report', ['id' => $report1], '*', MUST_EXIST);
        $this->assertEquals(0, $r1->reporterid);
        $this->assertSame('', (string) $r1->comment);
        $this->assertEquals(0, $r1->reviewedby);

        $r2 = $DB->get_record('local_forumcare_report', ['id' => $report2], '*', MUST_EXIST);
        $this->assertEquals($otherreporter->id, $r2->reporterid);
        $this->assertSame('keep me', $r2->comment);

        $this->assertFalse($DB->record_exists('local_forumcare_hidden', ['postid' => $hiddenpost->id]));
        // The other hidden post's author was not approved: its backup stays, hider anonymised.
        $this->assertEquals(0, $DB->get_field('local_forumcare_hidden', 'hiddenby', ['postid' => $otherhidden->id], MUST_EXIST));
    }

    /**
     * The provider declares the userlist interface so core routes per-user
     * deletions within a context to it.
     */
    public function test_implements_userlist_provider(): void {
        $this->assertTrue(is_subclass_of(provider::class, \core_privacy\local\request\core_userlist_provider::class));
    }
}
