# Changes

## v1.1.4

- Privacy: erasing a post author now deletes the backup of their hidden post's
  original content, and "Mark as OK" never writes content back into a post that
  core has deleted or erased.
- Privacy: the provider now implements `core_userlist_provider`
  (`get_users_in_context()`, `delete_data_for_users()`), so per-user deletions
  within a forum (e.g. partly expired contexts) reach this plugin's data.
- Privacy: erasing a reporter also blanks their free-text report comment, not
  just their user id.
- Course moderators (anyone with `local/forumcare:reviewreports` or
  `moodle/course:update` in the course), holders of
  `local/forumcare:suspendsitewide` and site admins are never suspended by the
  automatic thresholds or the manual suspend actions; the review queue no longer
  offers suspend buttons for them.
- Reporting checks that the reporter can see the post (separate groups, private
  replies, deleted posts); a missing or unviewable post gives the same error.
  The report-status service skips such posts too.
- An author who edits a hidden post can no longer undo the hide: the post is
  re-hidden and the edited text becomes the content "Mark as OK" restores.
  Saving a hidden post without changing it keeps the original content even
  when the placeholder was written in another language or the placeholder
  string was customised since (the placeholder written is now stored with the
  backup; an upgrade step adds and back-fills it).
- Restoring a forum cleans the backed-up forum care settings (enabled to 0/1,
  thresholds to a non-negative integer or empty) instead of writing them verbatim.
- Requires Moodle 5.0 or later (`$plugin->requires` raised to match the
  supported range; it previously allowed 4.5, which was never supported).
- composer.json requires `moodle/moodle` `^5.0`, matching the supported range.
- Continuous integration now tests against the released Moodle 5.3
  (MOODLE_503_STABLE) instead of Moodle's development branch.
- Releases are now also published to the camp plugin registry.
