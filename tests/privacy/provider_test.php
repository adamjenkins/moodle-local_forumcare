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
            'comment' => '',
            'status' => 'pending',
            'timecreated' => time(),
        ]);

        $cm = get_coursemodule_from_instance('forum', $this->forum->id, $this->course->id);
        $modcontext = \context_module::instance($cm->id);

        $approvedcontextlist = new approved_contextlist($reporter, 'local_forumcare', [$modcontext->id]);
        provider::delete_data_for_user($approvedcontextlist);

        $report = $DB->get_record('local_forumcare_report', ['id' => $reportid], '*', MUST_EXIST);
        $this->assertEquals(0, $report->reporterid);
    }
}
