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

namespace local_forumcare\forms;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Add/edit form for a report reason.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reason_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('reasonname', 'local_forumcare'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('checkbox', 'enabled', get_string('enabled', 'local_forumcare'));
        $mform->setDefault('enabled', 1);

        $mform->addElement('text', 'sortorder', get_string('sortorder', 'local_forumcare'));
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }
}
