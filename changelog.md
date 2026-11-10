# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.2.2] - 2026-11-10

### Added

- Backup with user data (`backup_local_forumcare_plugin`) includes the
  forum's `local_forumcare_hidden` rows (`hiddenposts/hiddenpost`, with
  `hiddenby` annotated as a user). Restore maps them to the new post ids in
  `after_restore_module()`, builds each row from the known fields only, sets
  `originalmessagetrust` to 0 and records the restored post's message as the
  placeholder. Posts without a mapping, or already backed, are skipped.
- The privacy export lists reports filed against the user's own posts
  (role `reportee`: post id, reason, status, outcome, time), without the
  reporter's identity or comment.
- `helper::is_protected_from_sitewide_suspension()` and new string
  `errorcannotsuspendsitewideprivileged`.
- New tests in `tests/backup_restore_test.php`,
  `tests/moderate_report_test.php`, `tests/privacy/provider_test.php` and
  `tests/submit_report_test.php`.

### Changed

- `settings:thresholdsuspendsitewide_desc` says who is never suspended
  site-wide. README documents backup and restore and the wider protection.
- The `postid-reporterid` index on `local_forumcare_report` is no longer
  unique (`install.xml`; upgrade step 2026111000 drops the unique index and
  adds a non-unique one).

### Fixed

- Site-wide suspension, automatic and manual (`suspend_site`), checked only
  the user's role in the reported post's course. It now also spares anyone
  with `local/forumcare:reviewreports` or `moodle/course:update` in any
  course, and anyone with a role assignment at system or category level, as
  well as site admins and holders of `local/forumcare:suspendsitewide`.
  The review queue hides "Suspend site-wide" for them, and `report.php`
  shows the new error as a notification.
- A privacy erasure of a second reporter of the same post failed on the
  unique index, as both reporter ids are anonymised to 0.
- A double submit could file two reports by the same user on one post:
  `submit_report` now checks for an existing report and inserts under a
  lock (`local_forumcare_report`, per post and user).
- Restoring a forum backup lost the original content of hidden posts,
  leaving the placeholder for good.

## [1.2.1] - 2026-11-03

### Changed

- The release notes and the AutoReport tests use neutral example strings
  ("spam", "buy now") instead of offensive words. No code changes.

## [1.2.0] - 2026-11-03

### Added

- AutoReport: each report reason has an AutoReport switch (off by default)
  and a list of telltale strings, one per line. A scheduled task
  (`\local_forumcare\task\autoreport`) queues ad hoc scans
  (`\local_forumcare\task\autoreport_scan_posts`) of the posts written since
  its last run, in blocks of 500 posts (at most 20 per run), and reports
  matching posts as "AutoReport". New fields `autoreport` and
  `autoreportstrings` on `local_forumcare_reason`; new strings `autoreport*`,
  `errorautoreportnostrings`, `errorautoreporttoomany`, `taskautoreport`.
  A reason holds at most 200 strings.
- A "Report type" filter on the review queue: all reports, AutoReport only,
  or reports by people only (new strings `filtersource*`).

### Fixed

- The review queue no longer drops reports whose reporter was anonymised by
  a privacy deletion; the reporter shows as "Unknown user".
- The review queue's post excerpt no longer shows HTML entities such as
  `&nbsp;` literally.
- Moderation actions in the review queue return to the same filtered view
  (forum, reporter, reportee, report type, show all) instead of the
  unfiltered default.

### Security

- Separate groups are respected in the review queue and in moderation: a
  moderator without `moodle/site:accessallgroups` sees and can act on only
  reports about all-participants discussions and their own groups'
  discussions, in the queue, its reporter/reportee filters, `report.php`
  and the `moderate_report` web service (new string `errorreportothergroup`).

## [1.1.6] - 2026-10-16

### Added

- A sortable "Reported" column (date and time of the report) in the review
  queue (new string `timereported`).
- A "Show all reports" / "Show pending and recent only" toggle on the review
  queue (new strings `showall`, `showrecent`, `showingrecent`).

### Changed

- The review queue lists pending reports and reports reviewed in the last
  7 days by default; older reviewed and ignored reports appear under
  "Show all reports".

### Fixed

- Paging and sorting the review queue no longer drop the forum, reporter and
  reportee filters.

## [1.1.5] - 2026-10-12

### Added

- A flag icon and a notice at the top of the report dialog, saying the report
  goes privately to the forum moderators and is not a reply (new strings
  `reportnoticeheading`, `reportnotice`; new `styles.css`).
- A plugin icon (`pix/icon.svg`), shown on Site administration > Plugins overview.
- Behat coverage (`tests/behat/report_post.feature`) and a test data generator
  (`tests/generator/`) for reasons and per-forum enablement.

### Changed

- The "Report this post" link and the "Post reported" label are inserted first
  in a post's action links, before Permalink, Edit and Reply, so they are not
  clicked by mistake for Reply.

### Fixed

- Upgrade step 2026101200 re-hides posts whose author edited them while hidden
  under 1.1.3 or earlier: the 1.1.4 back-fill (2026100401) had stored the
  author's text as the placeholder, leaving the post visible. The edit becomes
  the backed-up original (`db/upgradelib.php`).
- Report reason names are escaped with `s()` in the "reason in use" redirect
  message and the delete confirmation on the reasons page.

## [1.1.4] - 2026-10-04

### Added

- Releases are also published to the camp plugin registry (camp release
  workflow).

### Changed

- Requires Moodle 5.0 or later (`$plugin->requires` raised to match the
  supported range; it previously allowed 4.5, which was never supported).
- composer.json requires `moodle/moodle` `^5.0`, matching the supported range.
- Continuous integration tests against the released Moodle 5.3
  (MOODLE_503_STABLE) instead of Moodle's development branch.
- Course moderators (anyone with `local/forumcare:reviewreports` or
  `moodle/course:update` in the course), holders of
  `local/forumcare:suspendsitewide` and site admins are never suspended by the
  automatic thresholds or the manual suspend actions; the review queue no longer
  offers suspend buttons for them.

### Fixed

- Privacy: erasing a post author now deletes the backup of their hidden post's
  original content, and "Mark as OK" never writes content back into a post that
  core has deleted or erased.
- Privacy: the provider now implements `core_userlist_provider`
  (`get_users_in_context()`, `delete_data_for_users()`), so per-user deletions
  within a forum reach this plugin's data.
- Privacy: erasing a reporter also blanks their free-text report comment.
- Reporting checks that the reporter can see the post (separate groups, private
  replies, deleted posts); a missing or unviewable post gives the same error.
  The report-status service skips such posts too.
- An author who edits a hidden post can no longer undo the hide: the post is
  re-hidden and the edited text becomes the content "Mark as OK" restores.
  Saving a hidden post without changing it keeps the original content even
  when the placeholder was written in another language or the placeholder
  string was customised since (the placeholder written is now stored with the
  backup; an upgrade step adds and back-fills it).
- Restoring a forum cleans the backed-up forum care settings instead of writing
  them verbatim.

## [1.1.3] - 2026-10-03

### Changed

- Declare Moodle 5.3 support.
- Site-wide suspension now uses `\core\user::update_user()` on Moodle 5.3+,
  where `user_update_user()` is deprecated; older versions keep using
  `user_update_user()`.

### Fixed

- Activity settings form callbacks no longer raise an "Undefined property
  $modulename" warning for other activities' forms built without it.

## [1.1.2] - 2026-08-04

### Added

- The full GPL-3.0 licence text is now included as `LICENSE` in the repository
  root. The plugin's licence is unchanged (GPL-3.0-or-later, as declared in
  `composer.json`); the file was simply missing.

## [1.1.1] - 2026-07-13

### Security

- Course and site-wide auto-suspension thresholds now count **distinct
  reporters** rather than total reports, so a single user can no longer suspend
  another user's enrolment by reporting many of their posts. Reporting your own
  post is now also rejected server-side, not only hidden in the UI.

### Fixed

- Site-wide suspension no longer fatals: it called `\core_user::update_user()`,
  which does not exist in Moodle 5.x. It now uses `user_update_user()`.
- Deleting a report reason that still has reports filed under it is now blocked
  (previously it silently removed those reports, including pending ones, from
  the review queue). Disable the reason instead.
- The privacy provider now services the hidden-post backup table it declares:
  original content is exported to its author, moderator ids are anonymised on
  erasure, and backups are purged on context deletion.
- Deleting or resetting a forum/course now also removes orphaned hidden-post
  backups.

### Added

- `db/uninstall.php` restores every still-hidden post's original content before
  the plugin's tables are dropped, preventing permanent loss of that content if
  the plugin is removed while posts are hidden.
- PHPUnit coverage for all of the above (suite now 34 tests).

## [1.1.0] - 2026-07-12

### Added

- Course lifecycle handling: event observers now clean up forum care data
  when a forum is deleted (its settings and reports), when a course is
  deleted (its reports, course settings and course-specific reasons), and
  when a course is reset (reports pointing at posts removed by the reset).
- Course backup and restore support: the per-forum forum care settings
  (opt-in and threshold overrides) are included in course/activity backups
  and restored with the forum. Reports themselves are deliberately excluded
  from backups, as they are moderation data about identifiable users.
- PHPUnit coverage for the new observers.

### Changed

- CI matrix corrected: Moodle 5.0 is tested on PHP 8.2-8.3 only (not 8.4),
  alongside Moodle 5.1 (PHP 8.2-8.4) and 5.2 (PHP 8.3-8.4).
- Added the moodle-release.yml workflow for automatic Moodle Plugins
  directory releases, and CHANGES.md release notes.

### Fixed

- `amd/src/report.js` is now genuine ES6 source, and `amd/build/report.min.js` (with its sourcemap) is built from it via `grunt amd`, replacing the previous hand-compiled AMD module that was kept identical to the source as a workaround for not having a working grunt toolchain.

## [1.0.0] - 2026-06-25

### Added

- "Report this post" link injected into every forum post via the Hooks API (`core\hook\output\before_footer_html_generation`), with no changes to `mod_forum` core.
- The report link is hidden on a user's own posts and replaced with an unlinked "Post reported" label on posts they've already reported.
- Configurable report reasons, manageable site-wide or overridden per course.
- Per-forum opt-in (disabled by default), embedded as a "Forum care" section in the forum's own *Edit settings* page via the `coursemodule_standard_elements` / `coursemodule_definition_after_data` / `coursemodule_edit_post_actions` hooks.
- Site-wide master enable/disable switch, independent of the per-forum opt-in.
- Configurable thresholds for auto-hiding a reported post, auto-suspending a user's course enrolment, optional admin-only auto-suspension of a user's account site-wide, and auto-blocking frivolous reporters (silently, with no UI difference for the blocked user).
- Per-forum threshold overrides, including the ability to set a threshold to `0` to disable that specific protection for a single forum.
- Teacher review queue, filterable by forum/reporter/reportee, with post/forum/reporter/reportee columns linking to the post, forum, and profile pages, and sortable columns.
- Moderator actions: Mark as OK, Suspend user in course, Suspend user site-wide, Mark as frivolous, Hide post (manual, ahead of the auto-hide threshold), and Undo review.
- Info-styled placeholder messages for hidden posts, with distinct wording for automatic vs manual hides.
- Full `core_privacy` GDPR provider.
- PHPUnit test coverage for threshold enforcement, moderation actions, course reason overrides, the frivolous-reporter block, and the privacy provider.
- GitHub Actions CI workflow running the full `moodle-plugin-ci` suite against Moodle 5.0, 5.1, and 5.2.
