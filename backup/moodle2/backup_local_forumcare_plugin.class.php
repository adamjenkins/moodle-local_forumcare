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

/**
 * Backup support for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backs up the per-forum forum care settings alongside each forum activity.
 *
 * Individual reports are deliberately not included in backups: they are
 * moderation data about identifiable users and would leak through course
 * copies shared between teachers or sites.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_forumcare_plugin extends backup_local_plugin {
    /**
     * Attach the forum care settings of a forum to its activity backup.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();

        if ($this->task->get_modulename() !== 'forum') {
            return $plugin;
        }

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $forumcare = new backup_nested_element(
            'forumcare',
            null,
            ['enabled', 'threshold_hide', 'threshold_suspend', 'threshold_frivolous']
        );
        $pluginwrapper->add_child($forumcare);

        $forumcare->set_source_table('local_forumcare_forum', ['forumid' => backup::VAR_ACTIVITYID]);

        return $plugin;
    }
}
