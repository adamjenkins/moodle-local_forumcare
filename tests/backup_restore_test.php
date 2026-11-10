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
 * Tests for backing up and restoring the per-forum forum care settings.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_local_forumcare_plugin
 * @covers     \backup_local_forumcare_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /**
     * Set up a course as admin.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Back up one forum activity and restore it into the same course as a new
     * activity, optionally editing the extracted module.xml in between.
     *
     * @param \stdClass $forum
     * @param callable|null $tamper Receives the module.xml contents and returns the new contents.
     * @return int The restored forum's id.
     */
    private function backup_and_restore(\stdClass $forum, ?callable $tamper = null): int {
        global $USER, $DB;

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $forum->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        if ($tamper) {
            $modulexml = make_backup_temp_directory($backupid) . '/activities/forum_' . $forum->cmid . '/module.xml';
            $this->assertFileExists($modulexml);
            $contents = file_get_contents($modulexml);
            $tampered = $tamper($contents);
            $this->assertNotEquals($contents, $tampered, 'The tamper callback must change the backup');
            file_put_contents($modulexml, $tampered);
        }

        $before = $DB->get_fieldset_select('forum', 'id', 'course = ?', [$this->course->id]);
        $rc = new \restore_controller(
            $backupid,
            $this->course->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $after = $DB->get_fieldset_select('forum', 'id', 'course = ?', [$this->course->id]);
        $new = array_values(array_diff($after, $before));
        $this->assertCount(1, $new);
        return (int) $new[0];
    }

    /**
     * Create a forum with forum care enabled and per-forum thresholds.
     *
     * @return \stdClass
     */
    private function create_configured_forum(): \stdClass {
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        helper::set_forum_enabled($forum->id, true);
        helper::set_forum_thresholds($forum->id, ['threshold_hide' => 3, 'threshold_suspend' => 4, 'threshold_frivolous' => null]);
        return $forum;
    }

    /**
     * The per-forum settings round-trip through backup and restore.
     */
    public function test_settings_round_trip(): void {
        global $DB;

        $forum = $this->create_configured_forum();
        $newforumid = $this->backup_and_restore($forum);

        $row = $DB->get_record('local_forumcare_forum', ['forumid' => $newforumid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->enabled);
        $this->assertEquals(3, $row->threshold_hide);
        $this->assertEquals(4, $row->threshold_suspend);
        $this->assertNull($row->threshold_frivolous);
    }

    /**
     * Back up one forum with user data and restore it into the same course as a
     * new activity, the way a recycle-bin or course restore carries its posts.
     *
     * @param \stdClass $forum
     * @return int The restored forum's id.
     */
    private function backup_and_restore_with_users(\stdClass $forum): int {
        global $USER, $DB;

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $forum->cmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $backupid = 'forumcare_hidden_' . $forum->id;
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));

        $before = $DB->get_fieldset_select('forum', 'id', 'course = ?', [$this->course->id]);
        $rc = new \restore_controller(
            $backupid,
            $this->course->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $after = $DB->get_fieldset_select('forum', 'id', 'course = ?', [$this->course->id]);
        $new = array_values(array_diff($after, $before));
        $this->assertCount(1, $new);
        return (int) $new[0];
    }

    /**
     * A hidden post's original content survives a backup and restore with user
     * data: the restored post stays hidden and "Mark as OK" can bring it back.
     */
    public function test_hidden_post_original_round_trip(): void {
        global $DB;

        $forum = $this->create_configured_forum();
        $author = $this->getDataGenerator()->create_user();
        $moderator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($moderator->id, $this->course->id, 'editingteacher');
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
            'message' => '<p>The original words</p>',
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        helper::hide_post((int) $post->id, (int) $moderator->id, false);
        $placeholder = $DB->get_field('forum_posts', 'message', ['id' => $post->id]);

        $newforumid = $this->backup_and_restore_with_users($forum);

        $newpost = $DB->get_record_sql(
            "SELECT p.*
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE d.forum = :forumid",
            ['forumid' => $newforumid],
            MUST_EXIST
        );
        $this->assertNotEquals($post->id, $newpost->id);
        $this->assertSame($placeholder, $newpost->message);

        $hidden = $DB->get_record('local_forumcare_hidden', ['postid' => $newpost->id], '*', MUST_EXIST);
        $this->assertSame('<p>The original words</p>', $hidden->originalmessage);
        $this->assertEquals($moderator->id, $hidden->hiddenby);
        $this->assertEquals(0, $hidden->originalmessagetrust);
        $this->assertSame($newpost->message, $hidden->placeholder);
        // The source post's own backup is untouched.
        $this->assertTrue($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));

        helper::unhide_post((int) $newpost->id);
        $this->assertSame('<p>The original words</p>', $DB->get_field('forum_posts', 'message', ['id' => $newpost->id]));
    }

    /**
     * Without user data no posts are restored, so no hidden-post rows are written.
     */
    public function test_import_without_users_writes_no_hidden_rows(): void {
        global $DB;

        $forum = $this->create_configured_forum();
        $author = $this->getDataGenerator()->create_user();
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        helper::hide_post((int) $post->id, 0, true);

        $this->backup_and_restore($forum);

        $this->assertEquals(1, $DB->count_records('local_forumcare_hidden'));
    }

    /**
     * Crafted values in a backup are cleaned the way the settings form cleans
     * them: enabled to 0/1, thresholds to a non-negative int, empty to NULL.
     */
    public function test_restore_cleans_crafted_values(): void {
        global $DB;

        $forum = $this->create_configured_forum();
        $newforumid = $this->backup_and_restore($forum, function (string $xml): string {
            $xml = preg_replace('~<enabled>1</enabled>~', '<enabled>7</enabled>', $xml, 1);
            $xml = preg_replace('~<threshold_hide>3</threshold_hide>~', '<threshold_hide>abc</threshold_hide>', $xml, 1);
            return preg_replace('~<threshold_suspend>4</threshold_suspend>~', '<threshold_suspend>-5</threshold_suspend>', $xml, 1);
        });

        $row = $DB->get_record('local_forumcare_forum', ['forumid' => $newforumid], '*', MUST_EXIST);
        $this->assertEquals(1, $row->enabled);
        $this->assertEquals(0, $row->threshold_hide);
        $this->assertEquals(0, $row->threshold_suspend);
        $this->assertNull($row->threshold_frivolous);
    }
}
