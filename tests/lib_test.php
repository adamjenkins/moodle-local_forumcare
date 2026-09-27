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

/**
 * Tests for the activity settings form callbacks in lib.php.
 *
 * These callbacks run for EVERY activity's settings form, not just the
 * forum's, so they must cope with form data shaped however the caller built
 * it (e.g. another plugin's unit test passing a bare DB record as the current
 * data, with no modulename property).
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_forumcare_is_forum_form
 * @covers     ::local_forumcare_coursemodule_standard_elements
 * @covers     ::local_forumcare_coursemodule_definition_after_data
 * @covers     ::local_forumcare_coursemodule_edit_post_actions
 */
final class lib_test extends \advanced_testcase {
    /**
     * Load the form and callback code under test.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/formslib.php');
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/forum/mod_form.php');
        require_once($CFG->dirroot . '/local/forumcare/lib.php');
    }

    /**
     * Run a callable with every PHP warning/notice turned into an exception,
     * so an undefined-property read fails the test outright rather than only
     * being reported as a PHPUnit "issue".
     *
     * @param callable $fn
     * @return void
     */
    protected function run_strict(callable $fn): void {
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Build a mocked activity settings form wrapper.
     *
     * @param string $class moodleform_mod subclass to mock
     * @param \stdClass $current the form's current data
     * @param \context $context
     * @return \moodleform_mod
     */
    protected function mock_form(string $class, \stdClass $current, \context $context): \moodleform_mod {
        $form = $this->createMock($class);
        $form->method('get_current')->willReturn($current);
        $form->method('get_context')->willReturn($context);
        $form->method('get_coursemodule')->willReturn(null);
        return $form;
    }

    /**
     * A non-forum activity form whose current data has no modulename must not
     * raise a warning and must not get the forum care section.
     */
    public function test_non_forum_form_without_modulename_is_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Mirrors mod_quizquest's mod_form_test: a bare instance record as current data.
        $current = (object) ['id' => 1, 'instance' => 1, 'coursemodule' => 1, 'name' => 'Not a forum'];
        $formwrapper = $this->mock_form(\moodleform_mod::class, $current, $context);
        $mform = new \MoodleQuickForm('testform', 'post', '');

        $this->run_strict(function () use ($formwrapper, $mform): void {
            local_forumcare_coursemodule_standard_elements($formwrapper, $mform);
            local_forumcare_coursemodule_definition_after_data($formwrapper, $mform);
        });

        $this->assertFalse($mform->elementExists('forumcare_header'));
        $this->assertFalse($mform->elementExists('forumcare_enabled'));
    }

    /**
     * Positive control: the same callback does add the section for a forum
     * form, whether identified by modulename or by the forum's form class.
     */
    public function test_forum_form_gets_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        // Identified by modulename (the modedit.php shape).
        $formwrapper = $this->mock_form(\moodleform_mod::class, (object) ['modulename' => 'forum'], $context);
        $mform = new \MoodleQuickForm('testform1', 'post', '');
        $this->run_strict(function () use ($formwrapper, $mform): void {
            local_forumcare_coursemodule_standard_elements($formwrapper, $mform);
        });
        $this->assertTrue($mform->elementExists('forumcare_header'));
        $this->assertTrue($mform->elementExists('forumcare_enabled'));

        // Identified by form class, with no modulename in the current data.
        $formwrapper = $this->mock_form(\mod_forum_mod_form::class, (object) ['instance' => ''], $context);
        $mform = new \MoodleQuickForm('testform2', 'post', '');
        $this->run_strict(function () use ($formwrapper, $mform): void {
            local_forumcare_coursemodule_standard_elements($formwrapper, $mform);
        });
        $this->assertTrue($mform->elementExists('forumcare_header'));
    }

    /**
     * The post-actions callback must pass through module info that lacks
     * modulename without a warning and unchanged.
     */
    public function test_edit_post_actions_without_modulename(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $moduleinfo = (object) ['instance' => 1, 'forumcare_enabled' => 1];

        $result = null;
        $this->run_strict(function () use ($moduleinfo, $course, &$result): void {
            $result = local_forumcare_coursemodule_edit_post_actions($moduleinfo, $course);
        });

        $this->assertSame($moduleinfo, $result);
        $this->assertFalse(\local_forumcare\local\helper::is_enabled_for_forum(1));
    }
}
