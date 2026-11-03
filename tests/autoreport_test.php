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

use local_forumcare\local\autoreport;
use local_forumcare\local\helper;

/**
 * Tests for AutoReport: string matching, the scheduled queueing and the ad hoc scans.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_forumcare\local\autoreport
 * @covers     \local_forumcare\task\autoreport
 * @covers     \local_forumcare\task\autoreport_scan_posts
 */
final class autoreport_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $forum;

    /** @var \stdClass */
    private $student;

    /**
     * A course with one forum-care forum, a student, and no editing delay.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('maxeditingtime', 0);
        set_config('enabled', 1, 'local_forumcare');
        // Keep the thresholds out of the way unless a test sets one.
        set_config('threshold_hide', 0, 'local_forumcare');
        set_config('threshold_suspend', 0, 'local_forumcare');

        $this->course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        helper::set_forum_enabled($this->forum->id, true);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Create a post in a new discussion of the given forum.
     *
     * @param string $message
     * @param \stdClass|null $forum Defaults to the forum-care forum.
     * @return \stdClass The first post.
     */
    private function post(string $message, ?\stdClass $forum = null): \stdClass {
        global $DB;
        $forum = $forum ?? $this->forum;
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $forum->course,
            'forum' => $forum->id,
            'userid' => $this->student->id,
            'message' => $message,
        ]);
        return $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);
    }

    /**
     * Create a site-wide AutoReport reason.
     *
     * @param string $strings
     * @param array $extra
     * @return \stdClass
     */
    private function reason(string $strings, array $extra = []): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('local_forumcare')->create_reason(array_merge([
            'name' => 'AI use',
            'autoreport' => 1,
            'autoreportstrings' => $strings,
        ], $extra));
    }

    /**
     * The automatic reports filed so far, keyed by post id.
     *
     * @return array
     */
    private function autoreports(): array {
        global $DB;
        $reports = [];
        foreach ($DB->get_records('local_forumcare_report', ['reporterid' => autoreport::REPORTER_ID]) as $r) {
            $reports[$r->postid] = $r;
        }
        return $reports;
    }

    /**
     * Run the scheduled task and then the ad hoc scans it queued.
     */
    private function run_autoreport(): void {
        ob_start();
        (new \local_forumcare\task\autoreport())->execute();
        $this->runAdhocTasks(\local_forumcare\task\autoreport_scan_posts::class);
        ob_end_clean();
    }

    public function test_parse_and_match(): void {
        $strings = autoreport::parse_strings("  As an   AI \r\n\nas an ai\nfuck\n");
        $this->assertSame(['As an AI', 'fuck'], $strings);

        $text = autoreport::normalise_text('Essay', '<p>Well, AS AN</p><p>ai language model&nbsp;I think</p>');
        $this->assertSame(['As an AI'], autoreport::find_matches($text, $strings));
        $this->assertSame(['fuck'], autoreport::find_matches(autoreport::normalise_text('', 'What the FUCKING hell'), $strings));
        $this->assertSame([], autoreport::find_matches(autoreport::normalise_text('', 'A fine answer'), $strings));
    }

    public function test_matching_post_is_reported_once(): void {
        $reason = $this->reason("As an AI\nfuck");
        $bad = $this->post('<p>As an AI language model, I cannot...</p>');
        $good = $this->post('<p>My own thoughts.</p>');

        $this->run_autoreport();
        $reports = $this->autoreports();
        $this->assertSame([(int) $bad->id], array_map('intval', array_keys($reports)));
        $report = $reports[$bad->id];
        $this->assertEquals($reason->id, $report->reasonid);
        $this->assertSame('pending', $report->status);
        $this->assertStringContainsString('As an AI', $report->comment);
        $this->assertArrayNotHasKey($good->id, $reports);

        // The watermark is past both posts: a second run queues and files nothing.
        $this->assertEquals(max($bad->id, $good->id), get_config('local_forumcare', autoreport::WATERMARK));
        $this->run_autoreport();
        $this->assertCount(1, $this->autoreports());
        $this->assertEquals(0, autoreport::queue_new_posts());

        // Rescanning the same block (e.g. a retried task) does not duplicate the report.
        autoreport::scan_range(0, (int) $bad->id);
        $this->assertCount(1, $this->autoreports());
    }

    public function test_nothing_queued_while_off_then_backlog_scanned(): void {
        $off = $this->reason('fuck', ['autoreport' => 0]);
        $old = $this->post('fuck this');

        $this->run_autoreport();
        $this->assertSame([], $this->autoreports());
        $this->assertEmpty(get_config('local_forumcare', autoreport::WATERMARK));

        // Switching it on later scans the posts written before.
        global $DB;
        $DB->set_field('local_forumcare_reason', 'autoreport', 1, ['id' => $off->id]);
        $this->run_autoreport();
        $this->assertArrayHasKey($old->id, $this->autoreports());
    }

    public function test_skips_disabled_reasons_other_forums_and_recent_posts(): void {
        $this->reason('fuck', ['enabled' => 0]);
        $this->reason('As an AI');
        $plainforum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $disabledreasonpost = $this->post('fuck');
        $otherforumpost = $this->post('As an AI', $plainforum);

        $this->run_autoreport();
        $this->assertSame([], $this->autoreports());
        $this->assertArrayNotHasKey($disabledreasonpost->id, $this->autoreports());
        $this->assertArrayNotHasKey($otherforumpost->id, $this->autoreports());

        // Posts still inside the editing time are left for a later run.
        set_config('maxeditingtime', HOURSECS);
        $recent = $this->post('As an AI');
        $this->run_autoreport();
        $this->assertArrayNotHasKey($recent->id, $this->autoreports());
        $this->assertLessThan($recent->id, (int) get_config('local_forumcare', autoreport::WATERMARK));
    }

    public function test_course_override_reasons_apply(): void {
        $this->reason('As an AI');
        $this->reason('banana', ['courseid' => $this->course->id, 'name' => 'Off topic']);
        helper::set_course_override_reasons($this->course->id, true);
        $sitepost = $this->post('As an AI');
        $coursepost = $this->post('I like Banana bread');

        $this->run_autoreport();
        $reports = $this->autoreports();
        $this->assertArrayNotHasKey($sitepost->id, $reports);
        $this->assertArrayHasKey($coursepost->id, $reports);
    }

    public function test_autoreport_counts_towards_hide_threshold(): void {
        global $DB;
        set_config('threshold_hide', 1, 'local_forumcare');
        $this->reason('fuck');
        $post = $this->post('fuck');

        $this->run_autoreport();
        $this->assertTrue($DB->record_exists('local_forumcare_hidden', ['postid' => $post->id]));
    }

    public function test_forum_without_course_module_is_skipped_not_half_reported(): void {
        global $DB;
        $this->reason('fuck');
        $orphan = $this->post('fuck');
        $DB->delete_records('course_modules', ['id' => $this->forum->cmid]);
        \course_modinfo::purge_course_cache($this->course->id);

        $this->run_autoreport();
        $this->assertSame([], $this->autoreports());
        $this->assertEquals($orphan->id, get_config('local_forumcare', autoreport::WATERMARK));
    }

    public function test_reason_form_caps_the_strings(): void {
        $this->setAdminUser();
        $form = new \local_forumcare\forms\reason_form();
        $data = ['name' => 'Many', 'autoreport' => 1, 'sortorder' => 0];
        $data['autoreportstrings'] = implode("\n", range(1, autoreport::MAX_STRINGS));
        $this->assertArrayNotHasKey('autoreportstrings', $form->validation($data, []));
        $data['autoreportstrings'] .= "\nonemore";
        $this->assertArrayHasKey('autoreportstrings', $form->validation($data, []));
        $data['autoreportstrings'] = "  \n ";
        $this->assertArrayHasKey('autoreportstrings', $form->validation($data, []));
        $data['autoreport'] = 0;
        $this->assertSame([], $form->validation($data, []));

        // A longer list stored some other way is still only scanned up to the cap.
        $this->reason(implode("\n", range(1, autoreport::MAX_STRINGS + 5)));
        [[, $strings]] = autoreport::get_rules_for_course((int) $this->course->id);
        $this->assertCount(autoreport::MAX_STRINGS, $strings);
    }

    public function test_review_queue_source_filter(): void {
        global $DB;
        $this->setAdminUser();
        $reason = $this->reason('fuck');
        $auto = $this->post('fuck');
        $human = $this->post('A rude post with no telltale string');
        $this->run_autoreport();
        $reporter = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $DB->insert_record('local_forumcare_report', (object) [
            'postid' => $human->id, 'discussionid' => $human->discussion, 'forumid' => $this->forum->id,
            'courseid' => $this->course->id, 'reporterid' => $reporter->id, 'reasonid' => $reason->id,
            'status' => 'pending', 'timecreated' => time(),
        ]);

        $listed = function (string $source): array {
            $table = new \local_forumcare\output\report_table('t' . $source, (int) $this->course->id, 0, 0, 0, true, $source);
            $table->define_baseurl(new \moodle_url('/local/forumcare/report.php', ['courseid' => $this->course->id]));
            $table->setup();
            $table->query_db(10, false);
            $postids = array_map('intval', array_column(array_values($table->rawdata), 'postid'));
            sort($postids);
            return $postids;
        };
        $both = [(int) $auto->id, (int) $human->id];
        sort($both);
        $this->assertSame($both, $listed('all'));
        $this->assertSame([(int) $auto->id], $listed('auto'));
        $this->assertSame([(int) $human->id], $listed('human'));
    }

    public function test_review_queue_shows_autoreport_reporter(): void {
        $this->setAdminUser();
        $this->reason('fuck');
        $this->post('fuck');
        $this->run_autoreport();

        $table = new \local_forumcare\output\report_table('t', (int) $this->course->id, 0, 0, 0, true);
        $table->define_baseurl(new \moodle_url('/local/forumcare/report.php', ['courseid' => $this->course->id]));
        $table->setup();
        $table->query_db(10, false);
        $rows = array_values($table->rawdata);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString(
            get_string('autoreportreporter', 'local_forumcare'),
            $table->col_reporter($rows[0])
        );
    }
}
