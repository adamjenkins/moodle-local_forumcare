# Changes

## v1.2.1

This release contains everything in 1.2.0, with neutral example strings in
the release notes and tests. No changes to the plugin's behaviour since
1.2.0.

- **AutoReport.** Each report reason can now have a list of telltale
  strings (one per line, up to 200) and an AutoReport switch, off by
  default. A scheduled task scans new forum posts in forums that use forum
  care and reports any post containing one of the strings under that
  reason, for example "buy now" under "Spam" or "As an AI" under "Overuse
  of AI". Matching ignores case and extra spaces. Each post is scanned
  once, after the forum editing time, and existing posts are worked
  through gradually (at most 10,000 per run) when AutoReport is switched
  on part-way through a term. Automatic reports show "AutoReport" as the
  reporter, list the matched strings, and count towards the hide and
  suspend thresholds like any other report.
- A "Report type" filter on the review queue: all reports, AutoReport
  only, or reports by people only.
- Moderation actions return to the same filtered view of the review queue.
- Separate groups are respected: a moderator without "Access all groups"
  sees and can act on only reports in their own groups and in
  all-participants discussions.
- Reports whose reporter was removed by a privacy request stay in the
  queue (shown as "Unknown user") instead of disappearing.
- Post excerpts in the queue no longer show HTML entities such as
  `&nbsp;`.
