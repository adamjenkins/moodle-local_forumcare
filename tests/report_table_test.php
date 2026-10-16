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

use local_forumcare\output\report_table;

/**
 * Tests for the review queue table's default (recent) view and "show all".
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\output\report_table
 */
final class report_table_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var array Report ids keyed by label. */
    private $reportids = [];

    /**
     * Create one course with four reports: pending old, pending new,
     * reviewed recently and reviewed long ago (plus one ignored long ago).
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['summary' => '']);
        $forum = $gen->create_module('forum', ['course' => $this->course->id]);
        $reason = $gen->get_plugin_generator('local_forumcare')->create_reason(['name' => 'Spam']);
        $author = $gen->create_user();

        $now = time();
        $old = $now - 30 * DAYSECS;
        $cases = [
            'pendingold' => ['pending', $old, null],
            'pendingnew' => ['pending', $now - HOURSECS, null],
            'reviewedrecent' => ['reviewed', $old, $now - 2 * DAYSECS],
            'reviewedold' => ['reviewed', $old, $old + DAYSECS],
            'ignoredold' => ['ignored', $old, $old + DAYSECS],
        ];
        foreach ($cases as $label => [$status, $timecreated, $timereviewed]) {
            $discussion = $gen->get_plugin_generator('mod_forum')->create_discussion([
                'course' => $this->course->id,
                'forum' => $forum->id,
                'userid' => $author->id,
            ]);
            $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id], '*', MUST_EXIST);
            $this->reportids[$label] = (int) $DB->insert_record('local_forumcare_report', (object) [
                'postid' => $post->id,
                'discussionid' => $discussion->id,
                'forumid' => $forum->id,
                'courseid' => $this->course->id,
                'reporterid' => $gen->create_user()->id,
                'reasonid' => $reason->id,
                'comment' => '',
                'status' => $status,
                'outcome' => $status === 'reviewed' ? 'ok' : null,
                'timereviewed' => $timereviewed,
                'timecreated' => $timecreated,
            ]);
        }
    }

    /**
     * Run the table query and return the ids it lists.
     *
     * @param bool $showall
     * @return int[] Sorted report ids.
     */
    private function listed_ids(bool $showall): array {
        $table = new report_table('test-forumcare-reports', (int) $this->course->id, 0, 0, 0, $showall);
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
     * By default, every pending report shows whatever its age, and closed
     * reports only while reviewed within the last week.
     */
    public function test_default_view_lists_pending_and_recently_reviewed(): void {
        $this->assertSame($this->ids(['pendingold', 'pendingnew', 'reviewedrecent']), $this->listed_ids(false));
    }

    /**
     * "Show all" lists every report in the course.
     */
    public function test_show_all_lists_every_report(): void {
        $this->assertSame(array_values($this->ids(array_keys($this->reportids))), $this->listed_ids(true));
    }

    /**
     * The timestamp column renders the report's creation time.
     */
    public function test_timecreated_column_shows_report_time(): void {
        $table = new report_table('test-forumcare-reports', (int) $this->course->id, 0, 0, 0, true);
        $time = make_timestamp(2026, 3, 4, 13, 5);
        $expected = userdate($time, get_string('strftimedatetimeshort', 'core_langconfig'));
        $this->assertSame($expected, $table->col_timecreated((object) ['timecreated' => $time]));
    }
}
