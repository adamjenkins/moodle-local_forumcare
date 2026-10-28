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

use local_forumcare\external\moderate_report;
use local_forumcare\local\helper;
use local_forumcare\output\report_table;

/**
 * Tests that the review queue and moderation respect separate groups.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\local\helper
 * @covers     \local_forumcare\external\moderate_report
 * @covers     \local_forumcare\output\report_table
 */
final class group_moderation_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass Non-editing teacher, member of group A only. */
    private $tutor;

    /** @var \stdClass Editing teacher (holds accessallgroups), in no group. */
    private $editingteacher;

    /** @var int[] Report ids keyed by label: groupa, groupb, allparticipants. */
    private $reportids = [];

    /**
     * Create a course with a separate-groups forum, groups A and B, and one
     * report on a group A, a group B and an "all participants" discussion.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['summary' => '']);
        $forum = $gen->create_module('forum', ['course' => $this->course->id, 'groupmode' => SEPARATEGROUPS]);
        helper::set_forum_enabled($forum->id, true);
        $reason = $gen->get_plugin_generator('local_forumcare')->create_reason(['name' => 'Spam']);

        $groupa = $gen->create_group(['courseid' => $this->course->id]);
        $groupb = $gen->create_group(['courseid' => $this->course->id]);

        $this->tutor = $gen->create_user();
        $gen->enrol_user($this->tutor->id, $this->course->id, 'teacher');
        $gen->create_group_member(['groupid' => $groupa->id, 'userid' => $this->tutor->id]);

        $this->editingteacher = $gen->create_user();
        $gen->enrol_user($this->editingteacher->id, $this->course->id, 'editingteacher');

        $cases = ['groupa' => $groupa->id, 'groupb' => $groupb->id, 'allparticipants' => -1];
        foreach ($cases as $label => $groupid) {
            $author = $gen->create_user();
            $reporter = $gen->create_user();
            $gen->enrol_user($author->id, $this->course->id, 'student');
            $gen->enrol_user($reporter->id, $this->course->id, 'student');
            if ($groupid > 0) {
                $gen->create_group_member(['groupid' => $groupid, 'userid' => $author->id]);
                $gen->create_group_member(['groupid' => $groupid, 'userid' => $reporter->id]);
            }

            $discussion = $gen->get_plugin_generator('mod_forum')->create_discussion([
                'course' => $this->course->id,
                'forum' => $forum->id,
                'userid' => $author->id,
                'groupid' => $groupid,
            ]);
            $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
            $this->reportids[$label] = (int) $DB->insert_record('local_forumcare_report', (object) [
                'postid' => $post->id,
                'discussionid' => $discussion->id,
                'forumid' => $forum->id,
                'courseid' => $this->course->id,
                'reporterid' => $reporter->id,
                'reasonid' => $reason->id,
                'comment' => '',
                'status' => 'pending',
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * Run the review queue query as the current user and return the ids it lists.
     *
     * @return int[] Sorted report ids.
     */
    private function listed_ids(): array {
        $table = new report_table('test-forumcare-groups', (int) $this->course->id, 0, 0, 0, true);
        $table->define_baseurl(new \moodle_url('/local/forumcare/report.php', ['courseid' => $this->course->id]));
        $table->setup();
        $table->query_db(100, false);
        $ids = array_map('intval', array_keys($table->rawdata));
        sort($ids);
        return $ids;
    }

    /**
     * Expected ids for the given labels, sorted.
     *
     * @param string[] $labels
     * @return int[]
     */
    private function ids(array $labels): array {
        $ids = array_map(fn($label) => $this->reportids[$label], $labels);
        sort($ids);
        return $ids;
    }

    /**
     * A tutor bound by separate groups sees only their own group's and
     * "all participants" reports in the queue.
     */
    public function test_queue_hides_other_groups_reports_from_tutor(): void {
        $this->setUser($this->tutor);
        $this->assertSame($this->ids(['groupa', 'allparticipants']), $this->listed_ids());
    }

    /**
     * A moderator with accessallgroups still sees every report.
     */
    public function test_queue_lists_every_report_for_accessallgroups(): void {
        $this->setUser($this->editingteacher);
        $this->assertSame($this->ids(['groupa', 'groupb', 'allparticipants']), $this->listed_ids());
    }

    /**
     * The tutor cannot act on another group's report through the web service,
     * and the report stays pending.
     */
    public function test_tutor_cannot_moderate_other_groups_report(): void {
        global $DB;

        $this->setUser($this->tutor);
        try {
            moderate_report::execute($this->reportids['groupb'], 'frivolous');
            $this->fail('Moderating another group\'s report must be refused');
        } catch (\moodle_exception $e) {
            $this->assertEquals('errorreportothergroup', $e->errorcode);
        }
        $this->assertEquals('pending', $DB->get_field('local_forumcare_report', 'status', ['id' => $this->reportids['groupb']]));
    }

    /**
     * The tutor can still act on their own group's and "all participants" reports.
     */
    public function test_tutor_can_moderate_own_group_report(): void {
        global $DB;

        $this->setUser($this->tutor);
        moderate_report::execute($this->reportids['groupa'], 'frivolous');
        moderate_report::execute($this->reportids['allparticipants'], 'frivolous');

        $this->assertEquals('reviewed', $DB->get_field('local_forumcare_report', 'status', ['id' => $this->reportids['groupa']]));
        $this->assertEquals(
            'reviewed',
            $DB->get_field('local_forumcare_report', 'status', ['id' => $this->reportids['allparticipants']])
        );
    }

    /**
     * A moderator with accessallgroups can act on any group's report.
     */
    public function test_accessallgroups_can_moderate_any_group_report(): void {
        global $DB;

        $this->setUser($this->editingteacher);
        moderate_report::execute($this->reportids['groupb'], 'frivolous');
        $this->assertEquals('reviewed', $DB->get_field('local_forumcare_report', 'status', ['id' => $this->reportids['groupb']]));
    }
}
