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
 * Upgrade steps for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function for local_forumcare.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_forumcare_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026062501) {
        $table = new xmldb_table('local_forumcare_forum');

        $field = new xmldb_field('threshold_hide', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('threshold_suspend', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'threshold_hide');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('threshold_frivolous', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'threshold_suspend');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026062501, 'local', 'forumcare');
    }

    if ($oldversion < 2026062502) {
        $table = new xmldb_table('local_forumcare_reason');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        if (!$dbman->table_exists('local_forumcare_course')) {
            $table = new xmldb_table('local_forumcare_course');
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('override_reasons', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('courseid', XMLDB_INDEX_UNIQUE, ['courseid']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026062502, 'local', 'forumcare');
    }

    if ($oldversion < 2026062503) {
        // Event observers (db/events.php) and course backup/restore support
        // were added; there is no data to migrate.
        upgrade_plugin_savepoint(true, 2026062503, 'local', 'forumcare');
    }

    if ($oldversion < 2026100401) {
        // Store the placeholder written over each hidden post, so an edit that
        // keeps it is recognised whatever language it was written in.
        $table = new xmldb_table('local_forumcare_hidden');
        $field = new xmldb_field('placeholder', XMLDB_TYPE_TEXT, null, null, null, null, null, 'timehidden');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // A hidden post's live message is the placeholder (an edit re-writes it),
        // so back-fill the column from the post itself.
        $rs = $DB->get_recordset_sql(
            "SELECT h.id, p.message
               FROM {local_forumcare_hidden} h
               JOIN {forum_posts} p ON p.id = h.postid
              WHERE h.placeholder IS NULL"
        );
        foreach ($rs as $row) {
            $DB->set_field('local_forumcare_hidden', 'placeholder', $row->message, ['id' => $row->id]);
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026100401, 'local', 'forumcare');
    }

    return true;
}
