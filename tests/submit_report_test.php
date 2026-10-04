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

use local_forumcare\external\submit_report;
use local_forumcare\local\helper;

/**
 * Tests for the submit_report external function and threshold enforcement.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\external\submit_report
 */
final class submit_report_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $forum;

    /** @var \stdClass */
    private $discussion;

    /** @var int */
    private $reasonid;

    /**
     * Set up a course, forum, discussion and one enabled report reason.
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

        set_config('threshold_hide', 2, 'local_forumcare');
        set_config('threshold_suspend', 2, 'local_forumcare');
        set_config('threshold_suspend_sitewide', 0, 'local_forumcare');
        set_config('threshold_frivolous', 2, 'local_forumcare');
    }

    /**
     * Create a discussion and post by the given author.
     *
     * @param \stdClass $author
     * @return \stdClass The post record.
     */
    private function create_post(\stdClass $author): \stdClass {
        /** @var \mod_forum_generator $forumgenerator */
        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $author->id,
        ]);
        global $DB;
        return $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
    }

    /**
     * A user can submit a single report against a post.
     */
    public function test_submit_report_success(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
        $post = $this->create_post($author);

        $this->setUser($reporter);
        $result = submit_report::execute($post->id, $this->reasonid, 'Not appropriate');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $DB->count_records('local_forumcare_report', ['postid' => $post->id]));
    }

    /**
     * A user cannot report the same post twice.
     */
    public function test_duplicate_report_blocked(): void {
        $author = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
        $post = $this->create_post($author);

        $this->setUser($reporter);
        submit_report::execute($post->id, $this->reasonid, '');

        $this->expectException(\moodle_exception::class);
        submit_report::execute($post->id, $this->reasonid, '');
    }

    /**
     * Reporting is blocked when the per-forum opt-in is disabled.
     */
    public function test_reporting_blocked_when_forum_disabled(): void {
        $otherforum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        // Intentionally not enabled for this forum.

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $otherforum->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
        global $DB;
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);

        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');

        $this->setUser($reporter);
        $this->expectException(\moodle_exception::class);
        submit_report::execute($post->id, $this->reasonid, '');
    }

    /**
     * Once the hide threshold is reached, the post content is swapped for a
     * placeholder and the original is backed up.
     */
    public function test_post_auto_hidden_at_threshold(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $post = $this->create_post($author);
        $originalmessage = $post->message;

        $reporters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];

        foreach ($reporters as $reporter) {
            $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
            $this->setUser($reporter);
            submit_report::execute($post->id, $this->reasonid, '');
        }

        $updatedpost = $DB->get_record('forum_posts', ['id' => $post->id], '*', MUST_EXIST);
        $this->assertNotEquals($originalmessage, $updatedpost->message);

        $backup = $DB->get_record('local_forumcare_hidden', ['postid' => $post->id], '*', MUST_EXIST);
        $this->assertEquals($originalmessage, $backup->originalmessage);
    }

    /**
     * Once the suspend threshold is reached, the post author's enrolment in
     * this course is suspended.
     */
    public function test_author_auto_suspended_at_threshold(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');

        $reporters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];

        // Two different posts by the same author, reported by two different users,
        // so the per-course reportee count reaches the threshold of 2.
        foreach ($reporters as $reporter) {
            $post = $this->create_post($author);
            $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
            $this->setUser($reporter);
            submit_report::execute($post->id, $this->reasonid, '');
        }

        $enrolment = $DB->get_record_sql(
            "SELECT ue.* FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $this->course->id, 'userid' => $author->id]
        );
        $this->assertEquals(ENROL_USER_SUSPENDED, $enrolment->status);
    }

    /**
     * A single reporter filing reports against several different posts by the
     * same author must NOT reach the course-suspend threshold on their own: the
     * threshold counts distinct reporters, not total reports.
     */
    public function test_single_reporter_cannot_suspend_author(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');

        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');

        // Threshold_suspend is 2 (setUp). One reporter reports three different
        // posts by the author: that is one distinct reporter, below threshold.
        $this->setUser($reporter);
        for ($i = 0; $i < 3; $i++) {
            $post = $this->create_post($author);
            submit_report::execute($post->id, $this->reasonid, '');
        }

        $enrolment = $DB->get_record_sql(
            "SELECT ue.* FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $this->course->id, 'userid' => $author->id]
        );
        $this->assertEquals(ENROL_USER_ACTIVE, $enrolment->status);
    }

    /**
     * Have two distinct students each report a different post by the author,
     * which reaches the course-suspend threshold of 2 set in setUp.
     *
     * @param \stdClass $author
     */
    private function report_author_twice(\stdClass $author): void {
        for ($i = 0; $i < 2; $i++) {
            $post = $this->create_post($author);
            $reporter = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
            $this->setUser($reporter);
            submit_report::execute($post->id, $this->reasonid, '');
        }
    }

    /**
     * Status of the user's enrolment in the test course.
     *
     * @param int $userid
     * @return int
     */
    private function enrolment_status(int $userid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            "SELECT ue.status FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $this->course->id, 'userid' => $userid]
        );
    }

    /**
     * Students reaching the suspend threshold against a teacher's posts must not
     * suspend the teacher's enrolment: moderators are not suspension targets.
     */
    public function test_teacher_author_not_auto_suspended(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $this->report_author_twice($teacher);

        $this->assertEquals(ENROL_USER_ACTIVE, $this->enrolment_status((int) $teacher->id));
    }

    /**
     * A non-editing teacher (holds reviewreports) is protected from automatic suspension too.
     */
    public function test_noneditingteacher_author_not_auto_suspended(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'teacher');

        $this->report_author_twice($teacher);

        $this->assertEquals(ENROL_USER_ACTIVE, $this->enrolment_status((int) $teacher->id));
    }

    /**
     * Automatic site-wide suspension never suspends a site administrator.
     */
    public function test_siteadmin_author_not_auto_suspended_sitewide(): void {
        global $DB;
        set_config('threshold_suspend', 0, 'local_forumcare');
        set_config('threshold_suspend_sitewide', 2, 'local_forumcare');

        $admin = get_admin();
        $this->report_author_twice($admin);

        $this->assertEquals(0, $DB->get_field('user', 'suspended', ['id' => $admin->id]));
    }

    /**
     * A student author still is auto-suspended site-wide (the guard is not a blanket skip).
     */
    public function test_student_author_auto_suspended_sitewide(): void {
        global $DB;
        set_config('threshold_suspend', 0, 'local_forumcare');
        set_config('threshold_suspend_sitewide', 2, 'local_forumcare');

        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->report_author_twice($author);

        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $author->id]));
    }

    /**
     * In a separate-groups forum, a student cannot report a post in a group they are not in.
     */
    public function test_cannot_report_post_in_other_separate_group(): void {
        global $DB;

        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id,
            'groupmode' => SEPARATEGROUPS,
        ]);
        helper::set_forum_enabled($forum->id, true);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        $author = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $author->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $reporter->id]);

        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
            'groupid' => $groupb->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);

        $this->setUser($reporter);
        try {
            submit_report::execute($post->id, $this->reasonid, '');
            $this->fail('Reporting a post in another separate group must be refused');
        } catch (\moodle_exception $e) {
            $this->assertEquals('errorpostnotavailable', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('local_forumcare_report', ['postid' => $post->id]));
    }

    /**
     * A student cannot report a private reply addressed to someone else.
     */
    public function test_cannot_report_private_reply_to_someone_else(): void {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $this->course->id,
            'forum' => $this->forum->id,
            'userid' => $student->id,
        ]);
        $reply = $forumgenerator->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $teacher->id,
            'privatereplyto' => $student->id,
        ]);

        $this->setUser($reporter);
        try {
            submit_report::execute($reply->id, $this->reasonid, '');
            $this->fail('Reporting a private reply to someone else must be refused');
        } catch (\moodle_exception $e) {
            $this->assertEquals('errorpostnotavailable', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('local_forumcare_report', ['postid' => $reply->id]));
    }

    /**
     * A post core has marked deleted cannot be reported.
     */
    public function test_cannot_report_deleted_post(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');
        $post = $this->create_post($author);
        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $post->id]);

        $this->setUser($reporter);
        try {
            submit_report::execute($post->id, $this->reasonid, '');
            $this->fail('Reporting a deleted post must be refused');
        } catch (\moodle_exception $e) {
            $this->assertEquals('errorpostnotavailable', $e->errorcode);
        }
    }

    /**
     * A post id that does not exist gives the same error as an invisible post,
     * so the endpoint does not reveal which ids exist.
     */
    public function test_nonexistent_post_same_error(): void {
        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');

        $this->setUser($reporter);
        try {
            submit_report::execute(999999999, $this->reasonid, '');
            $this->fail('Reporting a missing post must be refused');
        } catch (\moodle_exception $e) {
            $this->assertEquals('errorpostnotavailable', $e->errorcode);
        }
    }

    /**
     * A user cannot report their own post (enforced server-side, not just in the UI).
     */
    public function test_cannot_report_own_post(): void {
        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $post = $this->create_post($author);

        $this->setUser($author);
        $this->expectException(\moodle_exception::class);
        submit_report::execute($post->id, $this->reasonid, '');
    }

    /**
     * A user blocked by the frivolous-report threshold must see no difference:
     * submission still succeeds, but the report is recorded as 'ignored' and
     * never counts toward any threshold (e.g. doesn't trigger auto-hide).
     */
    public function test_frivolous_reporter_blocked_silently(): void {
        global $DB;

        $reporter = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($reporter->id, $this->course->id, 'student');

        // Manually create two reports already marked frivolous for this reporter.
        for ($i = 0; $i < 2; $i++) {
            $author = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
            $post = $this->create_post($author);
            $DB->insert_record('local_forumcare_report', (object) [
                'postid' => $post->id,
                'discussionid' => $post->discussion,
                'forumid' => $this->forum->id,
                'courseid' => $this->course->id,
                'reporterid' => $reporter->id,
                'reasonid' => $this->reasonid,
                'comment' => '',
                'status' => 'reviewed',
                'outcome' => 'frivolous',
                'timecreated' => time(),
            ]);
        }

        $this->assertTrue(helper::is_reporter_blocked($reporter->id, $this->forum->id));

        // Threshold_hide is 2 (set in setUp); a single ignored report must not hide the post.
        $newauthor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($newauthor->id, $this->course->id, 'student');
        $newpost = $this->create_post($newauthor);
        $originalmessage = $newpost->message;

        $this->setUser($reporter);
        $result = submit_report::execute($newpost->id, $this->reasonid, '');

        $this->assertTrue($result['success']);

        $storedreport = $DB->get_record('local_forumcare_report', [
            'postid' => $newpost->id,
            'reporterid' => $reporter->id,
        ], '*', MUST_EXIST);
        $this->assertEquals('ignored', $storedreport->status);

        $this->assertEquals(0, helper::count_open_reports_for_post($newpost->id));
        $unchangedpost = $DB->get_record('forum_posts', ['id' => $newpost->id], '*', MUST_EXIST);
        $this->assertEquals($originalmessage, $unchangedpost->message);
    }
}
