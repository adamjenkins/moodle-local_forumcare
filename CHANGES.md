# Changes

## v1.1.0

- Course lifecycle handling: deleting a forum or a course, or resetting a
  course, now cleans up the related forum care settings and reports.
- Course backup/restore support: per-forum forum care settings (opt-in and
  threshold overrides) travel with the forum in course backups. Reports are
  deliberately excluded — they are moderation data about identifiable users.
- CI tests Moodle 5.0, 5.1 and 5.2 with compatible PHP versions
  (5.0: 8.2-8.3, 5.1: 8.2-8.4, 5.2: 8.3-8.4) on PostgreSQL and MariaDB.

## v1.0.0

First public release.

- Students can report forum posts (with configurable reasons) via a link
  injected into every post of an opted-in forum — no core changes.
- Teachers get a filterable, sortable review queue with one-click moderation
  actions: mark OK, hide post, suspend in course, suspend site-wide, mark
  frivolous, and undo.
- Automatic protections with configurable thresholds: auto-hide heavily
  reported posts, auto-suspend heavily reported users, auto-block frivolous
  reporters. Thresholds can be overridden (or disabled) per forum.
- Events, web services, privacy (GDPR) provider and PHPUnit tests included.
