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
