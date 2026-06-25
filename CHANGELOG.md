# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
