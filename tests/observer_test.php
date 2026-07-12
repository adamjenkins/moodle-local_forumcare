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
 * Tests for the course lifecycle event observers.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\local\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a course with a forum, a post by a student, and a report against it.
     *
     * @return array [course, forum record, cm, post id, report id]
     */
    protected function create_reported_post(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('forum', $forum->id);

        $author = $generator->create_and_enrol($course, 'student');
        $reporter = $generator->create_and_enrol($course, 'student');

        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);

        helper::set_forum_enabled($forum->id, true);
        $reasonid = $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Offensive',
            'courseid' => null,
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);
        $reportid = $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $post->id,
            'discussionid' => $discussion->id,
            'forumid' => $forum->id,
            'courseid' => $course->id,
            'reporterid' => $reporter->id,
            'reasonid' => $reasonid,
            'comment' => 'Test report',
            'status' => 'pending',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return [$course, $forum, $cm, (int) $post->id, (int) $reportid];
    }

    /**
     * Deleting a forum module removes its settings row and its reports.
     */
    public function test_forum_deletion_cleans_up(): void {
        global $DB;

        [, $forum, $cm, , ] = $this->create_reported_post();

        $this->assertTrue($DB->record_exists('local_forumcare_forum', ['forumid' => $forum->id]));
        $this->assertTrue($DB->record_exists('local_forumcare_report', ['forumid' => $forum->id]));

        course_delete_module($cm->id);

        $this->assertFalse($DB->record_exists('local_forumcare_forum', ['forumid' => $forum->id]));
        $this->assertFalse($DB->record_exists('local_forumcare_report', ['forumid' => $forum->id]));
    }

    /**
     * Deleting a course removes reports, course settings and course reasons.
     */
    public function test_course_deletion_cleans_up(): void {
        global $DB;

        [$course, $forum, , , ] = $this->create_reported_post();
        helper::set_course_override_reasons($course->id, true);
        $DB->insert_record('local_forumcare_reason', (object) [
            'name' => 'Course-specific reason',
            'courseid' => $course->id,
            'enabled' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
        ]);

        delete_course($course->id, false);

        $this->assertFalse($DB->record_exists('local_forumcare_report', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('local_forumcare_course', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('local_forumcare_reason', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('local_forumcare_forum', ['forumid' => $forum->id]));
    }

    /**
     * Resetting a course (deleting all forum posts) removes reports that
     * point at posts which no longer exist, and only those.
     */
    public function test_course_reset_removes_orphaned_reports(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        [$course, , , $postid, $reportid] = $this->create_reported_post();

        // A second course whose report must survive the first course's reset.
        [, , , , $otherreportid] = $this->create_reported_post();

        $this->setAdminUser();
        $resetdata = new \stdClass();
        $resetdata->id = $course->id;
        $resetdata->reset_forum_all = true;
        reset_course_userdata($resetdata);

        $this->assertFalse($DB->record_exists('forum_posts', ['id' => $postid]));
        $this->assertFalse($DB->record_exists('local_forumcare_report', ['id' => $reportid]));
        $this->assertTrue($DB->record_exists('local_forumcare_report', ['id' => $otherreportid]));
    }
}
