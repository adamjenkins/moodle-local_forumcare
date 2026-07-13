# Changes

## v1.1.1

- Security: course and site-wide auto-suspension now count distinct reporters,
  so one person can no longer suspend another user by reporting several of their
  posts. Reporting your own post is rejected server-side too.
- Fixed a fatal in site-wide suspension (used a non-existent core method).
- Report reasons that already have reports can no longer be deleted (which
  previously hid those reports from the review queue) — disable them instead.
- Privacy: the hidden-post backup table is now exported, anonymised and purged
  as the privacy API requires, and a new uninstall step restores still-hidden
  posts before the plugin's tables are dropped.
