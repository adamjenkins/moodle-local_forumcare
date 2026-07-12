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

namespace local_forumcare\output;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Filterable table of forum post reports for the teacher review page.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_table extends \table_sql {
    /** @var int */
    protected $courseid;

    /** @var bool Whether the current user can suspend a user site-wide. */
    protected $cansuspendsitewide;

    /** @var array Cache of forumid => cmid, to avoid repeated lookups per row. */
    protected $cmidcache = [];

    /**
     * Constructor.
     *
     * @param string $uniqueid
     * @param int $courseid
     * @param int $forumid 0 for all forums in the course.
     * @param int $reporterid 0 for all reporters.
     * @param int $reporteeid 0 for all reportees.
     */
    public function __construct(string $uniqueid, int $courseid, int $forumid, int $reporterid, int $reporteeid) {
        parent::__construct($uniqueid);

        $this->courseid = $courseid;
        $this->cansuspendsitewide = has_capability(
            'local/forumcare:suspendsitewide',
            \context_system::instance()
        );

        $columns = ['post', 'forum', 'reporter', 'reportee', 'reason', 'reportcount', 'status', 'actions'];
        $headers = [
            get_string('post', 'local_forumcare'),
            get_string('forum', 'local_forumcare'),
            get_string('reporter', 'local_forumcare'),
            get_string('reportee', 'local_forumcare'),
            get_string('reason', 'local_forumcare'),
            get_string('reportcount', 'local_forumcare'),
            get_string('status', 'local_forumcare'),
            get_string('actions', 'local_forumcare'),
        ];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->collapsible(false);
        $this->sortable(true);

        // Actions is the one column with no sensible sort order at all.
        $this->no_sorting('actions');

        [$fields, $from, $where, $params] = $this->build_query($courseid, $forumid, $reporterid, $reporteeid);
        $this->set_sql($fields, $from, $where, $params);
    }

    /**
     * Map each sortable column to the real SQL expression(s) to order by.
     * None of these columns are single plain SQL columns (composite names,
     * aliases, or a correlated subquery), so the default sort mechanism
     * (which just uses the column name verbatim) can't be used - this
     * overrides it with an explicit, correct mapping instead.
     *
     * @return string
     */
    public function get_sql_sort() {
        $columnexpressions = [
            'post' => ['p.subject'],
            'forum' => ['f.name'],
            'reporter' => ['reporter.lastname', 'reporter.firstname'],
            'reportee' => ['reportee.lastname', 'reportee.firstname'],
            'reason' => ['rs.name'],
            'reportcount' => ['reportcount'],
            'status' => ['r.status'],
        ];

        $sortcolumns = $this->get_sort_columns();
        $parts = [];

        foreach ($sortcolumns as $column => $order) {
            if (empty($columnexpressions[$column])) {
                continue;
            }
            $dir = $order == SORT_ASC ? 'ASC' : 'DESC';
            foreach ($columnexpressions[$column] as $expression) {
                $parts[] = "$expression $dir";
            }
        }

        if (isset($sortcolumns['post'])) {
            // Post title ties are broken by newest first, regardless of the
            // direction chosen for the title itself.
            $parts[] = 'r.timecreated DESC';
        }

        if (empty($parts)) {
            return 'r.timecreated DESC';
        }

        return implode(', ', $parts);
    }

    /**
     * Build the base SQL for the report list.
     *
     * @param int $courseid
     * @param int $forumid
     * @param int $reporterid
     * @param int $reporteeid
     * @return array [fields, from, where, params]
     */
    protected function build_query(int $courseid, int $forumid, int $reporterid, int $reporteeid): array {
        $reporterfields = \core_user\fields::for_name()->get_sql('reporter', false, 'reporter', '', false)->selects;
        $reporteefields = \core_user\fields::for_name()->get_sql('reportee', false, 'reportee', '', false)->selects;
        $fields = 'r.id, r.postid, r.discussionid, r.forumid, r.courseid, r.reporterid, r.reasonid, r.comment,
                   r.status, r.outcome, r.timecreated, p.userid AS reporteeid, p.subject, p.message,
                   p.deleted AS postdeleted, f.name AS forumname, rs.name AS reasonname,
                   ' . $reporterfields . ', ' . $reporteefields . ',
                   (SELECT COUNT(*) FROM {local_forumcare_report} r2
                     WHERE r2.postid = r.postid AND r2.status = \'pending\') AS reportcount';
        $from = '{local_forumcare_report} r
                 JOIN {forum_posts} p ON p.id = r.postid
                 JOIN {forum} f ON f.id = r.forumid
                 JOIN {local_forumcare_reason} rs ON rs.id = r.reasonid
                 JOIN {user} reporter ON reporter.id = r.reporterid
                 JOIN {user} reportee ON reportee.id = p.userid';

        $where = ['r.courseid = :courseid'];
        $params = ['courseid' => $courseid];

        if ($forumid) {
            $where[] = 'r.forumid = :forumid';
            $params['forumid'] = $forumid;
        }
        if ($reporterid) {
            $where[] = 'r.reporterid = :reporterid';
            $params['reporterid'] = $reporterid;
        }
        if ($reporteeid) {
            $where[] = 'p.userid = :reporteeid';
            $params['reporteeid'] = $reporteeid;
        }

        return [$fields, $from, implode(' AND ', $where), $params];
    }

    /**
     * Render the post column: subject links to the post itself, with an
     * excerpt of the (real, unredacted) content underneath.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_post(\stdClass $row): string {
        $text = $row->postdeleted ? get_string('outcome:deleted', 'local_forumcare') : shorten_text(strip_tags($row->message), 100);

        $posturl = new \moodle_url('/mod/forum/discuss.php', ['d' => $row->discussionid], 'p' . $row->postid);
        $subject = \html_writer::link($posturl, format_string($row->subject));

        return $subject . '<br><small class="text-muted">' . s($text) . '</small>';
    }

    /**
     * Render the forum column, linking the name to the forum itself.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_forum(\stdClass $row): string {
        $cmid = $this->get_forum_cmid($row->forumid, $row->courseid);
        if (!$cmid) {
            return format_string($row->forumname);
        }

        $forumurl = new \moodle_url('/mod/forum/view.php', ['id' => $cmid]);
        return \html_writer::link($forumurl, format_string($row->forumname));
    }

    /**
     * Render the reporter column, linking the name to their profile.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_reporter(\stdClass $row): string {
        $name = fullname(username_load_fields_from_object((object) [], $row, 'reporter'));
        $profileurl = new \moodle_url('/user/profile.php', ['id' => $row->reporterid, 'course' => $row->courseid]);
        return \html_writer::link($profileurl, $name);
    }

    /**
     * Render the reportee column, linking the name to their profile.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_reportee(\stdClass $row): string {
        $name = fullname(username_load_fields_from_object((object) [], $row, 'reportee'));
        $profileurl = new \moodle_url('/user/profile.php', ['id' => $row->reporteeid, 'course' => $row->courseid]);
        return \html_writer::link($profileurl, $name);
    }

    /**
     * Resolve and cache the course module id for a forum.
     *
     * @param int $forumid
     * @param int $courseid
     * @return int|null
     */
    protected function get_forum_cmid(int $forumid, int $courseid): ?int {
        if (!array_key_exists($forumid, $this->cmidcache)) {
            $cm = get_coursemodule_from_instance('forum', $forumid, $courseid, false, IGNORE_MISSING);
            $this->cmidcache[$forumid] = $cm ? (int) $cm->id : null;
        }
        return $this->cmidcache[$forumid];
    }

    /**
     * Render the reason column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_reason(\stdClass $row): string {
        $text = format_string($row->reasonname);
        if (!empty($row->comment)) {
            $text .= '<br><small class="text-muted">' . s($row->comment) . '</small>';
        }
        return $text;
    }

    /**
     * Render the report count column (total open reports against this post).
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_reportcount(\stdClass $row): string {
        return (string) $row->reportcount;
    }

    /**
     * Render the status column.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_status(\stdClass $row): string {
        if ($row->status === 'pending') {
            return get_string('status:pending', 'local_forumcare');
        }
        if ($row->status === 'ignored') {
            return get_string('status:ignored', 'local_forumcare');
        }
        $outcome = $row->outcome ? get_string('outcome:' . $row->outcome, 'local_forumcare') : '';
        return get_string('status:reviewed', 'local_forumcare') . ($outcome ? " ($outcome)" : '');
    }

    /** @var string[] Bootstrap button colour class per moderator action. */
    const ACTION_BUTTON_CLASSES = [
        'ok' => 'btn-success',
        'suspend_course' => 'btn-danger',
        'suspend_site' => 'btn-danger',
        'frivolous' => 'btn-warning',
        'hide' => 'btn-warning',
        'undo' => 'btn-outline-secondary',
    ];

    /**
     * Render the moderator action buttons: full action set while pending,
     * a single "undo" once reviewed, nothing for ignored reports.
     *
     * @param \stdClass $row
     * @return string
     */
    public function col_actions(\stdClass $row): string {
        if ($row->status === 'reviewed') {
            return $this->action_button($row, 'undo', 'action:undo');
        }

        if ($row->status !== 'pending') {
            return '';
        }

        $actions = ['ok' => 'action:markok'];
        if (!$this->is_post_hidden($row->postid)) {
            $actions['hide'] = 'action:hidepost';
        }
        $actions['suspend_course'] = 'action:suspendcourse';
        if ($this->cansuspendsitewide) {
            $actions['suspend_site'] = 'action:suspendsite';
        }
        $actions['frivolous'] = 'action:markfrivolous';

        $buttons = [];
        foreach ($actions as $action => $langkey) {
            $buttons[] = $this->action_button($row, $action, $langkey);
        }

        return implode(' ', $buttons);
    }

    /**
     * Build a single colour-coded moderator action button.
     *
     * @param \stdClass $row
     * @param string $action
     * @param string $langkey
     * @return string
     */
    protected function action_button(\stdClass $row, string $action, string $langkey): string {
        $url = new \moodle_url('/local/forumcare/report.php', [
            'courseid' => $row->courseid,
            'reportid' => $row->id,
            'moderate' => $action,
            'sesskey' => sesskey(),
        ]);
        $colourclass = self::ACTION_BUTTON_CLASSES[$action] ?? 'btn-outline-secondary';

        return \html_writer::link(
            $url,
            get_string($langkey, 'local_forumcare'),
            ['class' => 'btn btn-sm ' . $colourclass . ' me-1 mb-1']
        );
    }

    /**
     * Whether a post is currently hidden pending review.
     *
     * @param int $postid
     * @return bool
     */
    protected function is_post_hidden(int $postid): bool {
        global $DB;
        return $DB->record_exists('local_forumcare_hidden', ['postid' => $postid]);
    }
}
