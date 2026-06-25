# Forum care (local_forumcare)

A Moodle local plugin that lets students report problematic posts in `mod_forum` forums, with configurable auto-moderation (hide posts, suspend users) and a teacher review queue.

## Features

- **"Report this post" / "Post reported" link** injected into every forum post's action menu (mod_forum has no native hook for this, so it's added client-side via a `core/modal_save_cancel` modal). The link is omitted entirely on a user's own posts, and replaced with an unlinked "Post reported" label on posts they've already reported.
- **Configurable report reasons**, either site-wide defaults or overridden per course (e.g. "Offensive language", "Overuse of AI").
- **Per-forum opt-in** — reporting is disabled by default for every forum; a teacher/manager enables it (and may override the site-wide thresholds) from a "Forum care" section embedded directly in the forum's own *Edit settings* page.
- **Site-wide master switch** in Site administration, independent of the per-forum opt-in.
- **Configurable thresholds**:
  - Auto-hide a post once it receives N open reports.
  - Auto-suspend a user's enrolment in a course once their posts receive N open reports in that course.
  - Optional admin-only auto-suspend of a user's account site-wide once their posts receive N open reports across the whole site.
  - Auto-block a user from submitting further reports once N of their past reports have been marked frivolous by a moderator. A blocked user sees no difference in the UI — their report is silently recorded but excluded from every threshold count.
  - Any per-forum threshold can be set to `0` to disable that specific protection for that forum only.
- **Teacher review queue** (`/local/forumcare/report.php`), filterable by forum/reporter/reportee, with the post title, forum name, and reporter/reportee names linking to the post, forum, and profile pages respectively. Reportable columns are independently sortable.
- **Colour-coded moderator actions**: Mark as OK (green), Suspend in course / Suspend site-wide (red), Mark as frivolous / Hide post (yellow), and Undo review (revert a reviewed report back to pending).
- Hidden-post placeholders are styled as Bootstrap info notices, with distinct wording for automatic (threshold-triggered) vs manual (teacher-clicked "Hide post") hides.
- Full GDPR privacy provider (`core_privacy`).

## Requirements

- Moodle 4.5 (`2024100700`) or later. Tested against Moodle 5.0, 5.1, and 5.2 (see `.github/workflows/ci.yml`).
- `mod_forum` (bundled with Moodle core).

## Installation

1. Copy (or git clone) this plugin into `local/forumcare` in your Moodle codebase.
2. Visit *Site administration → Notifications* to run the database install/upgrade.
3. Configure thresholds and the site-wide switch at *Site administration → Plugins → Local plugins → Forum care*.
4. Add at least one report reason at *Site administration → Plugins → Local plugins → Forum care → Report reasons* (or per-course, see below).
5. For each forum you want to enable reporting on: open the forum's *Edit settings* page and expand the **Forum care** section to enable it (and optionally override thresholds for that forum).

## Configuring report reasons per course

By default, every course offers the site-wide default reasons. A teacher or manager can override this for a single course from the **Forum care report reasons** link in that course's navigation: enable "Override the site-wide default report reasons for this course" and add the course's own list.

## Capabilities

| Capability | Context | Default roles |
|---|---|---|
| `local/forumcare:report` | Module | Student, Teacher, Editing teacher, Manager |
| `local/forumcare:reviewreports` | Course | Teacher, Editing teacher, Manager |
| `local/forumcare:managereasons` | System | Manager |
| `local/forumcare:managecoursereasons` | Course | Editing teacher, Manager |
| `local/forumcare:suspendsitewide` | System | Manager |

## Privacy

This plugin stores personal data (who reported what, and why) and implements the full `core_privacy` API. Since report rows are shared between the reporter, the reportee, and the reviewer, deleting a user's data anonymises that user's own identifying fields on the row rather than deleting the row outright, since other users still need it as moderation history.

## Known limitations

- Post deletion is intentionally **not** implemented by this plugin. A moderator deleting post content should use `mod_forum`'s own existing delete-post flow (requires `mod/forum:deleteanypost`).
- Manually undoing a review (the "Undo review" action) resets the report back to `pending` but does **not** reverse any side effect already taken, e.g. an enrolment suspension — only the report's own status/outcome.

## Development

- Code style: Moodle coding standard (`local_codechecker` / `moodle-plugin-ci phpcs`).
- Tests: `tests/` contains PHPUnit coverage for threshold enforcement, moderation actions, course reason overrides, and the privacy provider.
- CI: see `.github/workflows/ci.yml`, which runs the full `moodle-plugin-ci` suite (phplint, phpcs, phpdoc, validate, savepoints, mustache, grunt, phpunit, behat) against Moodle 5.0, 5.1, and 5.2.

## License

GPL v3 or later. See <http://www.gnu.org/copyleft/gpl.html>.
