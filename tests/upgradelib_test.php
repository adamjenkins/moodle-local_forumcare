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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/forumcare/db/upgradelib.php');

/**
 * Tests for the upgrade repair of hidden posts edited before the edit observer existed.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_forumcare_repair_hidden_placeholders
 */
final class upgradelib_test extends \advanced_testcase {
    /**
     * Create a forum post and hide it the way the plugin does.
     *
     * @param string $message
     * @return \stdClass The post record after hiding.
     */
    private function create_hidden_post(string $message): \stdClass {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'message' => $message,
        ]);
        helper::hide_post((int) $discussion->firstpost, 0, true);
        return $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
    }

    /**
     * Simulate an author's edit made under 1.1.3 or earlier, followed by the 1.1.4 back-fill,
     * which recorded that edit as the placeholder.
     *
     * @param int $postid
     * @param string $edit
     */
    private function simulate_old_edit_and_backfill(int $postid, string $edit): void {
        global $DB;
        $DB->set_field('forum_posts', 'message', $edit, ['id' => $postid]);
        $DB->set_field('local_forumcare_hidden', 'placeholder', $edit, ['postid' => $postid]);
    }

    /**
     * A post edited while hidden is hidden again, and the edit becomes the backed-up original.
     */
    public function test_edited_post_is_rehidden_and_edit_backed_up(): void {
        global $DB;
        $this->resetAfterTest();
        $post = $this->create_hidden_post('<p>Original rude text</p>');
        $this->simulate_old_edit_and_backfill($post->id, '<p>Edited by the author</p>');

        local_forumcare_repair_hidden_placeholders();

        $placeholder = \html_writer::div(get_string('hiddenpostplaceholder', 'local_forumcare'), 'alert alert-info');
        $this->assertSame($placeholder, $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
        $backup = $DB->get_record('local_forumcare_hidden', ['postid' => $post->id], '*', MUST_EXIST);
        $this->assertSame('<p>Edited by the author</p>', $backup->originalmessage);
        $this->assertSame($placeholder, $backup->placeholder);
    }

    /**
     * A post still showing the placeholder keeps its original content untouched.
     */
    public function test_correctly_hidden_post_is_left_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $post = $this->create_hidden_post('<p>Original rude text</p>');
        $before = $DB->get_record('local_forumcare_hidden', ['postid' => $post->id], '*', MUST_EXIST);

        local_forumcare_repair_hidden_placeholders();

        $after = $DB->get_record('local_forumcare_hidden', ['postid' => $post->id], '*', MUST_EXIST);
        $this->assertEquals($before, $after);
        $this->assertSame($post->message, $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
    }

    /**
     * An editor round trip of the placeholder (different markup, same text) is still a placeholder:
     * the original is kept and only the placeholder column follows the post.
     */
    public function test_reformatted_placeholder_is_not_treated_as_edit(): void {
        global $DB;
        $this->resetAfterTest();
        $post = $this->create_hidden_post('<p>Original rude text</p>');
        $reformatted = '<p>' . get_string('hiddenpostplaceholder', 'local_forumcare') . '</p>';
        $this->simulate_old_edit_and_backfill($post->id, $reformatted);
        $DB->set_field('local_forumcare_hidden', 'placeholder', null, ['postid' => $post->id]);

        local_forumcare_repair_hidden_placeholders();

        $backup = $DB->get_record('local_forumcare_hidden', ['postid' => $post->id], '*', MUST_EXIST);
        $this->assertSame('<p>Original rude text</p>', $backup->originalmessage);
        $this->assertSame($reformatted, $backup->placeholder);
        $this->assertSame($reformatted, $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
    }

    /**
     * A post core has deleted is not touched.
     */
    public function test_deleted_post_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();
        $post = $this->create_hidden_post('<p>Original rude text</p>');
        $this->simulate_old_edit_and_backfill($post->id, '<p>Edited by the author</p>');
        $DB->set_field('forum_posts', 'deleted', 1, ['id' => $post->id]);

        local_forumcare_repair_hidden_placeholders();

        $this->assertSame('<p>Edited by the author</p>', $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
        $original = $DB->get_field('local_forumcare_hidden', 'originalmessage', ['postid' => $post->id]);
        $this->assertSame('<p>Original rude text</p>', $original);
    }
}
