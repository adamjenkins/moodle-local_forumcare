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
 * Teacher review queue for forum post reports.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_forumcare\local\helper;
use local_forumcare\output\report_table;

$courseid = required_param('courseid', PARAM_INT);
$forumid = optional_param('forumid', 0, PARAM_INT);
$reporterid = optional_param('reporterid', 0, PARAM_INT);
$reporteeid = optional_param('reporteeid', 0, PARAM_INT);

$moderate = optional_param('moderate', '', PARAM_ALPHANUMEXT);
$reportid = optional_param('reportid', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/forumcare:reviewreports', $context);

$baseurl = new moodle_url('/local/forumcare/report.php', ['courseid' => $courseid]);
$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('reviewreports', 'local_forumcare'));
$PAGE->set_heading($course->fullname);

if ($moderate !== '' && $reportid) {
    require_sesskey();

    if ($moderate === 'suspend_site') {
        require_capability('local/forumcare:suspendsitewide', context_system::instance());
    }

    if (in_array($moderate, helper::VALID_MODERATION_ACTIONS, true)) {
        $report = $DB->get_record('local_forumcare_report', ['id' => $reportid], '*', MUST_EXIST);
        if ((int) $report->courseid === $courseid) {
            helper::apply_moderation($reportid, $moderate, $USER->id);
        }
    }

    redirect($baseurl, get_string('actiondone', 'local_forumcare'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reviewreports', 'local_forumcare'));

// Filters.
$forums = $DB->get_records('forum', ['course' => $courseid], 'name ASC', 'id, name');
$forumoptions = [0 => get_string('allforums', 'local_forumcare')];
foreach ($forums as $forum) {
    $forumoptions[$forum->id] = format_string($forum->name);
}

$userssql = "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {user} u
              WHERE u.id IN (
                  SELECT r.reporterid FROM {local_forumcare_report} r WHERE r.courseid = :courseid1
                  UNION
                  SELECT p.userid FROM {local_forumcare_report} r
                  JOIN {forum_posts} p ON p.id = r.postid
                  WHERE r.courseid = :courseid2
              )";
$relevantusers = $DB->get_records_sql($userssql, ['courseid1' => $courseid, 'courseid2' => $courseid]);
$useroptions = [0 => get_string('allusers', 'local_forumcare')];
foreach ($relevantusers as $u) {
    $useroptions[$u->id] = fullname($u);
}

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out(false), 'class' => 'mb-3 d-flex gap-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);

echo html_writer::start_tag('div');
echo html_writer::tag('label', get_string('filterforum', 'local_forumcare'), ['class' => 'me-1']);
echo html_writer::select($forumoptions, 'forumid', $forumid, false);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div');
echo html_writer::tag('label', get_string('filterreporter', 'local_forumcare'), ['class' => 'me-1']);
echo html_writer::select($useroptions, 'reporterid', $reporterid, false);
echo html_writer::end_tag('div');

echo html_writer::start_tag('div');
echo html_writer::tag('label', get_string('filterreportee', 'local_forumcare'), ['class' => 'me-1']);
echo html_writer::select($useroptions, 'reporteeid', $reporteeid, false);
echo html_writer::end_tag('div');

echo html_writer::tag('button', get_string('applyfilter', 'local_forumcare'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$table = new report_table('local-forumcare-reports', $courseid, $forumid, $reporterid, $reporteeid);
$table->define_baseurl($baseurl);
$table->out(25, false);

echo $OUTPUT->footer();
