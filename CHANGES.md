# Changes

## v1.2.2

Fixes to site-wide suspension, privacy requests, backup and restore, and
duplicate reports.

- **Site-wide suspension spares more moderators.** A site-wide suspension
  locks the whole account, so neither the automatic threshold nor the
  "Suspend site-wide" action now suspends anyone who can review reports or
  edit any course on the site, or who has a role at site or category level.
  Before, only their role in the reported post's course was checked. The
  review queue no longer offers "Suspend site-wide" for such users, and a
  clear message explains why if it is attempted.
- **Privacy erasure.** Deleting the data of a second user who reported the
  same post no longer fails.
- **Privacy export.** A user's data export now also lists the reports filed
  against their own posts (reason, status and outcome), without saying who
  filed them or showing the reporter's comment.
- **Backup and restore.** A forum backup with user data (including the
  recycle bin's automatic backups) now carries the original content of its
  hidden posts. After a restore, a hidden post stays hidden and its original
  content is kept, instead of being lost and leaving only the placeholder.
  Reports themselves are still never backed up.
- Clicking Report twice quickly can no longer file two reports for the same
  post by the same user.
