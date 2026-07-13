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
 * Uninstall hook for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore every hidden post's original content before the plugin's tables are
 * dropped.
 *
 * Hiding a post overwrites the live {forum_posts} row with a placeholder and
 * keeps the only copy of the original in {local_forumcare_hidden}. If the plugin
 * were uninstalled with posts still hidden, that table would be dropped and the
 * originals lost forever, leaving the placeholder in place permanently. This
 * hook runs before the schema is torn down, so it restores them first.
 *
 * @return bool
 */
function xmldb_local_forumcare_uninstall(): bool {
    global $DB;

    $backups = $DB->get_recordset('local_forumcare_hidden');
    foreach ($backups as $backup) {
        if (!$DB->record_exists('forum_posts', ['id' => $backup->postid])) {
            continue;
        }
        $DB->update_record('forum_posts', (object) [
            'id' => $backup->postid,
            'message' => $backup->originalmessage,
            'messageformat' => $backup->originalmessageformat,
            'messagetrust' => $backup->originalmessagetrust,
        ]);
    }
    $backups->close();

    return true;
}
