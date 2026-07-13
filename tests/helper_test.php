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
 * Tests for site-wide / per-forum enablement and threshold resolution.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\local\helper
 */
final class helper_test extends \advanced_testcase {
    /**
     * Set up a course and forum.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The site-wide switch is a master gate: disabling it blocks reporting
     * even when a forum has individually opted in.
     */
    public function test_site_wide_switch_overrides_per_forum_opt_in(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        helper::set_forum_enabled($forum->id, true);
        $this->assertTrue(helper::is_site_enabled());
        $this->assertTrue(helper::can_report_in_forum($forum->id));

        set_config('enabled', 0, 'local_forumcare');
        $this->assertFalse(helper::is_site_enabled());
        $this->assertFalse(helper::can_report_in_forum($forum->id));
    }

    /**
     * A forum that hasn't opted in cannot report even if the site switch is on.
     */
    public function test_forum_must_individually_opt_in(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $this->assertTrue(helper::is_site_enabled());
        $this->assertFalse(helper::is_enabled_for_forum($forum->id));
        $this->assertFalse(helper::can_report_in_forum($forum->id));
    }

    /**
     * A per-forum threshold override takes precedence over the site default;
     * an unset override falls back to the site default.
     */
    public function test_per_forum_threshold_override(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        set_config('threshold_hide', 3, 'local_forumcare');
        $this->assertEquals(3, helper::get_threshold($forum->id, 'threshold_hide'));

        helper::set_forum_thresholds($forum->id, ['threshold_hide' => 7]);
        $this->assertEquals(7, helper::get_threshold($forum->id, 'threshold_hide'));

        helper::set_forum_thresholds($forum->id, ['threshold_hide' => null]);
        $this->assertEquals(3, helper::get_threshold($forum->id, 'threshold_hide'));
    }

    /**
     * An explicit per-forum override of 0 disables that protection for this
     * forum (distinct from null, which inherits the site default).
     */
    public function test_zero_threshold_override_disables_protection(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        set_config('threshold_hide', 3, 'local_forumcare');

        helper::set_forum_thresholds($forum->id, ['threshold_hide' => 0]);
        $this->assertEquals(0, helper::get_threshold($forum->id, 'threshold_hide'));
    }

    /**
     * A reason is reported as in-use while any report references it, so the
     * management UI can refuse to hard-delete it (which would otherwise drop
     * those reports from the review queue's inner join).
     */
    public function test_reason_has_reports_detects_usage(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $reasonid = $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Spam',
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);

        $this->assertFalse(helper::reason_has_reports($reasonid));

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $post->id,
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'courseid' => $course->id,
            'reporterid' => $this->getDataGenerator()->create_user()->id,
            'reasonid' => $reasonid,
            'comment' => '',
            'status' => 'pending',
            'timecreated' => time(),
        ]);

        $this->assertTrue(helper::reason_has_reports($reasonid));
    }

    /**
     * Suspending a user site-wide sets the account's suspended flag through the
     * core user API (regression: it previously called a non-existent method).
     */
    public function test_suspend_sitewide_sets_suspended_flag(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->assertEquals(0, $user->suspended);

        helper::suspend_sitewide($user->id, 0, true);

        $this->assertEquals(1, $DB->get_field('user', 'suspended', ['id' => $user->id]));
    }

    /**
     * A course sees the site-wide default reasons until it explicitly
     * overrides them, at which point only its own reasons are offered.
     */
    public function test_course_reason_override(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $sitewideid = $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Site default reason',
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);

        $this->assertFalse(helper::is_course_overriding_reasons($course->id));
        $reasons = helper::get_reasons_for_course($course->id);
        $this->assertCount(1, $reasons);
        $this->assertEquals($sitewideid, $reasons[0]->id);

        $courseonlyid = $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Course-specific reason',
            'courseid' => $course->id,
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);

        // Adding a course-specific reason alone doesn't activate it; the
        // override switch is the explicit control for that.
        $reasons = helper::get_reasons_for_course($course->id);
        $this->assertCount(1, $reasons);
        $this->assertEquals($sitewideid, $reasons[0]->id);

        helper::set_course_override_reasons($course->id, true);
        $this->assertTrue(helper::is_course_overriding_reasons($course->id));

        $reasons = helper::get_reasons_for_course($course->id);
        $this->assertCount(1, $reasons);
        $this->assertEquals($courseonlyid, $reasons[0]->id);
    }
}
