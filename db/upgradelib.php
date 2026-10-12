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
 * Upgrade helpers for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Re-hide posts whose author edited them while hidden, before the edit observer existed.
 *
 * Up to 1.1.3 an author's edit simply overwrote the placeholder on a hidden post, so the post
 * showed again. The 1.1.4 upgrade then back-filled local_forumcare_hidden.placeholder from the
 * live post, recording that author text as if it were the placeholder. For each hidden post
 * whose live message is not a placeholder in any installed language, this treats the live text
 * as the author's latest edit (as the post_updated observer does today): it becomes the backed-up
 * original, and the real placeholder goes back on the post and into the row. Rows whose post shows
 * a placeholder only get their placeholder column brought in line with the post.
 *
 * Kept free of the plugin's autoloaded classes, as upgrade code should be.
 *
 * @return void
 */
function local_forumcare_repair_hidden_placeholders(): void {
    global $DB;

    $normalise = function (string $message): string {
        return trim(preg_replace('/\s+/u', ' ', html_to_text($message, 0, false)));
    };

    $keys = ['hiddenpostplaceholder', 'hiddenpostplaceholdermanual'];
    $manager = get_string_manager();
    $known = [];
    foreach (array_keys($manager->get_list_of_translations(true)) as $lang) {
        foreach ($keys as $key) {
            $known[$normalise($manager->get_string($key, 'local_forumcare', null, $lang))] = true;
        }
    }

    $rs = $DB->get_recordset_sql(
        "SELECT h.*, p.message AS livemessage, p.messageformat AS liveformat, p.messagetrust AS livetrust
           FROM {local_forumcare_hidden} h
           JOIN {forum_posts} p ON p.id = h.postid
          WHERE p.deleted = 0"
    );
    foreach ($rs as $row) {
        $live = (string) $row->livemessage;
        // The exact shape the plugin writes is also accepted, so a placeholder whose lang string
        // was customised since it was written is not mistaken for an author's edit.
        $isplaceholder = isset($known[$normalise($live)])
            || preg_match('~^\s*<div class="alert alert-info">[^<>]*</div>\s*$~', $live);

        if ($isplaceholder) {
            if ($row->placeholder !== $live) {
                $DB->set_field('local_forumcare_hidden', 'placeholder', $live, ['id' => $row->id]);
            }
            continue;
        }

        $placeholder = html_writer::div(get_string($keys[empty($row->hiddenby) ? 0 : 1], 'local_forumcare'), 'alert alert-info');
        $DB->update_record('local_forumcare_hidden', (object) [
            'id' => $row->id,
            'originalmessage' => $live,
            'originalmessageformat' => $row->liveformat,
            'originalmessagetrust' => $row->livetrust,
            'placeholder' => $placeholder,
        ]);
        $DB->update_record('forum_posts', (object) [
            'id' => $row->postid,
            'message' => $placeholder,
            'messageformat' => FORMAT_HTML,
            'messagetrust' => 0,
        ]);
    }
    $rs->close();
}
