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
 * Restore support for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the per-forum forum care settings backed up with each forum activity,
 * and the original content of its hidden posts when user data was restored.
 *
 * Both elements are read while the module information is restored, before the
 * forum instance and its posts exist, so they are stashed and only written in
 * after_restore_module() once the new forum and post ids are known.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_forumcare_plugin extends restore_local_plugin {
    /** @var \stdClass|null Settings read from the backup, pending the new forum id. */
    protected $pendingsettings = null;

    /** @var \stdClass[] Hidden-post originals read from the backup, pending the new post ids. */
    protected $pendinghidden = [];

    /**
     * Declare the paths handled at module level.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('forumcare', $this->get_pathfor('/forumcare')),
            new restore_path_element('forumcare_hiddenpost', $this->get_pathfor('/hiddenposts/hiddenpost')),
        ];
    }

    /**
     * Stash one hidden post's backed-up original until the posts exist.
     *
     * @param array|\stdClass $data
     * @return void
     */
    public function process_forumcare_hiddenpost($data) {
        $this->pendinghidden[] = (object) $data;
    }

    /**
     * Stash one forumcare settings element until the activity exists.
     *
     * @param array|\stdClass $data
     * @return void
     */
    public function process_forumcare($data) {
        $this->pendingsettings = (object) $data;
    }

    /**
     * Write the stashed settings now that the restored forum instance exists.
     *
     * @return void
     */
    public function after_restore_module() {
        if ($this->task->get_modulename() !== 'forum') {
            return;
        }
        $this->restore_settings();
        $this->restore_hidden_posts();
    }

    /**
     * Write back the original content of hidden posts, keyed to the restored
     * post ids. The restored posts keep their placeholder, so they stay hidden,
     * and the original is kept for unhiding, the privacy export and uninstall.
     *
     * @return void
     */
    protected function restore_hidden_posts(): void {
        global $DB;

        foreach ($this->pendinghidden as $source) {
            $newpostid = (int) $this->get_mappingid('forum_post', (int) ($source->postid ?? 0), 0);
            if (!$newpostid) {
                // The posts were not restored (no user data), so there is nothing to back.
                continue;
            }
            $message = $DB->get_field('forum_posts', 'message', ['id' => $newpostid]);
            if ($message === false || $DB->record_exists('local_forumcare_hidden', ['postid' => $newpostid])) {
                continue;
            }

            // The .mbz is untrusted input: build the row from the known fields only.
            // The original is never trusted text, so it is cleaned when shown again.
            $row = new \stdClass();
            $row->postid = $newpostid;
            $row->originalmessage = (string) ($source->originalmessage ?? '');
            $row->originalmessageformat = clean_param($source->originalmessageformat ?? FORMAT_HTML, PARAM_INT);
            $row->originalmessagetrust = 0;
            $row->hiddenby = (int) $this->get_mappingid('user', (int) ($source->hiddenby ?? 0), 0);
            $row->timehidden = max(0, clean_param($source->timehidden ?? 0, PARAM_INT));
            // The restored post's message is the placeholder: record that exact text,
            // so an edit that keeps it is recognised (helper::rehide_edited_post()).
            $row->placeholder = $message;
            $DB->insert_record('local_forumcare_hidden', $row);
        }
        $this->pendinghidden = [];
    }

    /**
     * Write the stashed per-forum settings now that the forum instance exists.
     *
     * @return void
     */
    protected function restore_settings(): void {
        global $DB;

        if ($this->pendingsettings === null) {
            return;
        }

        // The .mbz is untrusted input: build the row from only the known fields,
        // cleaned the way the forum settings form cleans them.
        $source = $this->pendingsettings;
        $data = new \stdClass();
        $data->forumid = (int) $this->task->get_activityid();
        $data->enabled = empty($source->enabled) ? 0 : 1;
        foreach (['threshold_hide', 'threshold_suspend', 'threshold_frivolous'] as $name) {
            $value = $source->$name ?? null;
            $data->$name = ($value === null || $value === '') ? null : max(0, clean_param($value, PARAM_INT));
        }
        $data->timemodified = time();

        if ($existing = $DB->get_record('local_forumcare_forum', ['forumid' => $data->forumid])) {
            $data->id = $existing->id;
            $DB->update_record('local_forumcare_forum', $data);
        } else {
            $DB->insert_record('local_forumcare_forum', $data);
        }
    }
}
