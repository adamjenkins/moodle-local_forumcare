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
 * Tests for the uninstall hook restoring hidden posts.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_forumcare_uninstall
 */
final class uninstall_test extends \advanced_testcase {
    /**
     * Uninstalling restores every hidden post's original content before the
     * plugin's tables (the only copy of that content) are dropped.
     */
    public function test_uninstall_restores_hidden_posts(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $author = $generator->create_and_enrol($course, 'student');

        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $discussion = $forumgenerator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $author->id,
        ]);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
        $original = $post->message;

        helper::hide_post((int) $post->id, 0, true);
        $this->assertNotEquals($original, $DB->get_field('forum_posts', 'message', ['id' => $post->id]));

        require_once($CFG->dirroot . '/local/forumcare/db/uninstall.php');
        xmldb_local_forumcare_uninstall();

        $this->assertEquals($original, $DB->get_field('forum_posts', 'message', ['id' => $post->id]));
    }
}
