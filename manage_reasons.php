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
 * Manage forum report reasons, either the site-wide defaults or, when a
 * courseid is supplied, a course's own override list.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_forumcare\forms\reason_form;
use local_forumcare\local\helper;

$courseid = optional_param('courseid', 0, PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$iscoursescope = $courseid > 0;

if ($iscoursescope) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    require_capability('local/forumcare:managecoursereasons', $context);
} else {
    require_login();
    $context = context_system::instance();
    require_capability('local/forumcare:managereasons', $context);
}

$baseurl = new moodle_url('/local/forumcare/manage_reasons.php', $iscoursescope ? ['courseid' => $courseid] : []);

$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout($iscoursescope ? 'incourse' : 'admin');
$PAGE->set_title(get_string('reasons', 'local_forumcare'));
$PAGE->set_heading($iscoursescope ? $course->fullname : get_string('reasons', 'local_forumcare'));
navigation_node::override_active_url($baseurl);

// A reason belongs to this scope: course-specific reasons must match the
// requested course, site-wide management only ever touches courseid IS NULL.
$scopeconditions = $iscoursescope ? ['courseid' => $courseid] : ['courseid' => null];

if ($delete) {
    require_sesskey();
    $reason = $DB->get_record('local_forumcare_reason', array_merge(['id' => $delete], $scopeconditions), '*', MUST_EXIST);
    // Refuse to hard-delete a reason still referenced by reports: the review
    // queue inner-joins the reason table, so deleting it would silently drop
    // those reports (including pending ones) from the queue. Disabling the
    // reason via its "enabled" flag is the intended way to retire it.
    if (helper::reason_has_reports($delete)) {
        redirect(
            $baseurl,
            get_string('errorreasoninuse', 'local_forumcare', $reason->name),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('deletereasonconfirm', 'local_forumcare', $reason->name),
            new moodle_url($baseurl, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]),
            $baseurl
        );
        echo $OUTPUT->footer();
        exit;
    }
    $DB->delete_records('local_forumcare_reason', ['id' => $delete]);
    redirect($baseurl, get_string('reasondeleted', 'local_forumcare'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($iscoursescope && optional_param('toggleoverride', 0, PARAM_BOOL) && confirm_sesskey()) {
    $override = optional_param('override_reasons', 0, PARAM_BOOL);
    helper::set_course_override_reasons($courseid, (bool) $override);
    redirect($baseurl, get_string('actiondone', 'local_forumcare'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$reason = $id ? $DB->get_record('local_forumcare_reason', array_merge(['id' => $id], $scopeconditions), '*', MUST_EXIST) : null;
$form = new reason_form(new moodle_url($baseurl, $id ? ['id' => $id] : []));

if ($reason) {
    $reason->enabled = (bool) $reason->enabled;
    $form->set_data($reason);
}

if ($form->is_cancelled()) {
    redirect($baseurl);
} else if ($data = $form->get_data()) {
    $record = new stdClass();
    $record->name = $data->name;
    $record->courseid = $iscoursescope ? $courseid : null;
    $record->enabled = !empty($data->enabled) ? 1 : 0;
    $record->sortorder = $data->sortorder;

    if (!empty($data->id)) {
        // Re-confirm the existing row is actually in this scope before overwriting it.
        $DB->get_record('local_forumcare_reason', array_merge(['id' => $data->id], $scopeconditions), '*', MUST_EXIST);
        $record->id = $data->id;
        $DB->update_record('local_forumcare_reason', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('local_forumcare_reason', $record);
    }

    redirect($baseurl, get_string('reasonsaved', 'local_forumcare'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reasons', 'local_forumcare'));

if ($iscoursescope) {
    $overridenow = helper::is_course_overriding_reasons($courseid);
    echo html_writer::tag('p', get_string('courseoverridereasons_desc', 'local_forumcare'));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'toggleoverride', 'value' => 1]);
    echo html_writer::start_div('form-check form-switch mb-3');
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'class' => 'form-check-input',
        'id' => 'forumcare-override-reasons',
        'name' => 'override_reasons',
        'value' => 1,
        'checked' => $overridenow ? 'checked' : null,
    ]);
    echo html_writer::tag('label', get_string('courseoverridereasons', 'local_forumcare'), [
        'class' => 'form-check-label',
        'for' => 'forumcare-override-reasons',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');

    if (!$overridenow) {
        echo $OUTPUT->notification(get_string('courseoverridereasonsoff', 'local_forumcare'), 'info');
    }
}

$reasons = $DB->get_records('local_forumcare_reason', $scopeconditions, 'sortorder ASC, id ASC');

$table = new html_table();
$table->head = [
    get_string('reasonname', 'local_forumcare'),
    get_string('enabled', 'local_forumcare'),
    get_string('sortorder', 'local_forumcare'),
    get_string('actions', 'local_forumcare'),
];

foreach ($reasons as $r) {
    $editurl = new moodle_url($baseurl, ['id' => $r->id]);
    $deleteurl = new moodle_url($baseurl, ['delete' => $r->id, 'sesskey' => sesskey()]);
    $actions = html_writer::link($editurl, get_string('editreason', 'local_forumcare')) . ' | ' .
        html_writer::link($deleteurl, get_string('deletereason', 'local_forumcare'));

    $table->data[] = [
        s($r->name),
        $r->enabled ? get_string('yes') : get_string('no'),
        $r->sortorder,
        $actions,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->heading(get_string('addreason', 'local_forumcare'), 4);
$form->display();

echo $OUTPUT->footer();
