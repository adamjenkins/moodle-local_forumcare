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

use local_forumcare\external\get_post_report_status;
use local_forumcare\local\helper;

/**
 * Tests for the get_post_report_status external function, used by the
 * client to decide whether to show the report link, hide it (own post), or
 * replace it with "Post reported" (already reported by this user).
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\external\get_post_report_status
 */
final class get_post_report_status_test extends \advanced_testcase {
    /**
     * Set up a course, forum, and enrol two users.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A post is flagged as the viewer's own when they authored it, as
     * already reported once they've reported it, and neither otherwise.
     */
    public function test_own_post_and_already_reported_flags(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        helper::set_forum_enabled($forum->id, true);

        $author = $this->getDataGenerator()->create_user();
        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($viewer->id, $course->id, 'student');

        $forumgenerator = $this->getDataGenerator()->get_plugin_generator('mod_forum');

        $owndiscussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $viewer->id,
        ]);
        $ownpost = $DB->get_record('forum_posts', ['discussion' => $owndiscussion->id], '*', MUST_EXIST);

        $otherdiscussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
        ]);
        $otherpost = $DB->get_record('forum_posts', ['discussion' => $otherdiscussion->id], '*', MUST_EXIST);

        $reasonid = $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Offensive language',
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);
        $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $otherpost->id,
            'discussionid' => $otherdiscussion->id,
            'forumid' => $forum->id,
            'courseid' => $course->id,
            'reporterid' => $viewer->id,
            'reasonid' => $reasonid,
            'comment' => '',
            'status' => 'pending',
            'timecreated' => time(),
        ]);

        $this->setUser($viewer);
        $statuses = get_post_report_status::execute([$ownpost->id, $otherpost->id]);

        $byid = [];
        foreach ($statuses as $status) {
            $byid[$status['postid']] = $status;
        }

        $this->assertTrue($byid[$ownpost->id]['isown']);
        $this->assertFalse($byid[$ownpost->id]['reported']);

        $this->assertFalse($byid[$otherpost->id]['isown']);
        $this->assertTrue($byid[$otherpost->id]['reported']);
    }
}
