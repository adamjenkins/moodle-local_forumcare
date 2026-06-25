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
 * Language strings for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action:deletepost'] = 'Delete post content';
$string['action:hidepost'] = 'Hide post';
$string['action:markfrivolous'] = 'Mark as frivolous';
$string['action:markok'] = 'Mark as OK';
$string['action:suspendcourse'] = 'Suspend user in this course';
$string['action:suspendsite'] = 'Suspend user site-wide';
$string['action:undo'] = 'Undo review';
$string['actiondone'] = 'Action completed';
$string['actions'] = 'Actions';
$string['addreason'] = 'Add reason';
$string['allforums'] = 'All forums';
$string['allusers'] = 'All users';
$string['applyfilter'] = 'Apply filter';
$string['confirmaction'] = 'Are you sure you want to perform this action?';
$string['courseoverridereasons'] = 'Override the site-wide default report reasons for this course';
$string['courseoverridereasons_desc'] = 'When enabled, students reporting a post in any forum in this course will only see the reasons listed below, instead of the site-wide default reasons.';
$string['courseoverridereasonsoff'] = 'This course is currently using the site-wide default reasons. Any reasons added below will not be offered to reporters until the override is enabled above.';
$string['deletereason'] = 'Delete reason';
$string['deletereasonconfirm'] = 'Are you sure you want to delete the reason "{$a}"?';
$string['editreason'] = 'Edit reason';
$string['enabled'] = 'Enabled';
$string['enableforumcare'] = 'Enable "Report this post" for this forum';
$string['erroralreadyreported'] = 'You have already reported this post.';
$string['errorforumcaredisabled'] = 'Reporting is not enabled for this forum.';
$string['errorreasonrequired'] = 'Please select a reason for this report.';
$string['eventposthidden'] = 'Forum post hidden';
$string['eventpostreported'] = 'Forum post reported';
$string['eventreportactioned'] = 'Forum report actioned';
$string['eventusersuspended'] = 'User suspended for forum reports';
$string['filterforum'] = 'Forum';
$string['filterreportee'] = 'Reportee';
$string['filterreporter'] = 'Reporter';
$string['forum'] = 'Forum';
$string['forumcare:managecoursereasons'] = 'Manage course-level forum report reasons';
$string['forumcare:managereasons'] = 'Manage forum report reasons';
$string['forumcare:report'] = 'Report a forum post';
$string['forumcare:reviewreports'] = 'Review forum post reports';
$string['forumcare:suspendsitewide'] = 'Suspend a user site-wide from the forum report review page';
$string['forumthresholdoverride'] = 'Per-forum threshold override';
$string['forumthresholdoverride_help'] = 'Pre-filled with the current site-wide default (configured in Site administration). Change it to set a different threshold for this forum only, or set it to 0 to disable this protection entirely for this forum.';
$string['hiddenpostplaceholder'] = 'This post has been hidden pending review by a moderator.';
$string['hiddenpostplaceholdermanual'] = 'This post has been hidden by a moderator.';
$string['navmanagereasons'] = 'Forum care report reasons';
$string['navreviewreports'] = 'Forum care';
$string['outcome:deleted'] = 'Deleted';
$string['outcome:frivolous'] = 'Frivolous';
$string['outcome:ok'] = 'OK';
$string['pluginname'] = 'Forum care';
$string['post'] = 'Post';
$string['postreported'] = 'Post reported';
$string['privacy:metadata:local_forumcare_hidden'] = 'Backup of original content for posts hidden pending review.';
$string['privacy:metadata:local_forumcare_hidden:hiddenby'] = 'The ID of the user who hid the post, or 0 if hidden automatically.';
$string['privacy:metadata:local_forumcare_hidden:originalmessage'] = 'The original message content of the hidden post.';
$string['privacy:metadata:local_forumcare_report'] = 'Information about reports filed against forum posts.';
$string['privacy:metadata:local_forumcare_report:comment'] = 'Additional details provided by the reporter.';
$string['privacy:metadata:local_forumcare_report:outcome'] = 'The outcome decided by the reviewer.';
$string['privacy:metadata:local_forumcare_report:reasonid'] = 'The reason selected for the report.';
$string['privacy:metadata:local_forumcare_report:reporterid'] = 'The ID of the user who filed the report.';
$string['privacy:metadata:local_forumcare_report:reviewedby'] = 'The ID of the user who reviewed the report.';
$string['reason'] = 'Reason';
$string['reasondeleted'] = 'Reason deleted';
$string['reasonname'] = 'Reason name';
$string['reasons'] = 'Report reasons';
$string['reasonsaved'] = 'Reason saved';
$string['reportcomment'] = 'Additional details (optional)';
$string['reportcount'] = 'Reports';
$string['reportee'] = 'Reportee';
$string['reporter'] = 'Reporter';
$string['reportpost'] = 'Report post';
$string['reportreason'] = 'Reason for reporting';
$string['reportsubmitted'] = 'Your report has been submitted. Thank you for helping keep this forum safe.';
$string['reportthispost'] = 'Report this post';
$string['reviewreports'] = 'Forum reports';
$string['settings:enabled'] = 'Enable forum care';
$string['settings:enabled_desc'] = 'Master switch for the whole plugin. When disabled, reporting is unavailable everywhere regardless of any per-forum setting. Each forum must still be individually enabled in its own "Forum care" section (in the forum\'s edit-settings page) before students can report posts in it.';
$string['settings:managereasons'] = 'Manage report reasons';
$string['settings:managereasons_desc'] = 'Add, edit, enable, disable, or remove the reasons students can select when reporting a post.';
$string['settings:thresholdfrivolous'] = 'Frivolous reports before reporting is blocked';
$string['settings:thresholdfrivolous_desc'] = 'Number of a user\'s reports marked as frivolous by a reviewer before that user is blocked from submitting further reports.';
$string['settings:thresholdhide'] = 'Reports before auto-hide';
$string['settings:thresholdhide_desc'] = 'Number of open reports a single post must receive before it is automatically hidden pending review.';
$string['settings:thresholds'] = 'Moderation thresholds';
$string['settings:thresholds_desc'] = 'These are the site-wide defaults. Each forum has its own "Forum care" section (in its edit-settings page) where these can be overridden, or set to 0 to disable that protection for that forum only.';
$string['settings:thresholdsuspend'] = 'Reports before course suspension';
$string['settings:thresholdsuspend_desc'] = 'Number of open reports against a user\'s posts within a single course before their enrolment in that course is automatically suspended.';
$string['settings:thresholdsuspendsitewide'] = 'Reports before site-wide suspension';
$string['settings:thresholdsuspendsitewide_desc'] = 'Total open reports against a user across the whole site before their account is automatically suspended site-wide. Set to 0 to disable this admin-only feature; teachers cannot configure or trigger it.';
$string['sortorder'] = 'Sort order';
$string['status'] = 'Status';
$string['status:ignored'] = 'Ignored (frivolous reporter)';
$string['status:pending'] = 'Pending';
$string['status:reviewed'] = 'Reviewed';
