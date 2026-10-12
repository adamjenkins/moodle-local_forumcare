# Changes

## v1.1.5

- The "Report this post" link (and the "Post reported" label) now comes first
  in a post's links, before Permalink, Edit and Reply. Sites reported students
  filing reports while meaning to reply.
- The report dialog opens with a flag icon and a notice: "You are reporting
  this post. Your report goes privately to the forum moderators. It is not a
  reply and will not appear in the forum."
- The plugin has an icon on the Plugins overview page (Site administration).
- Upgrade fix: on sites upgrading from 1.1.3 or earlier, a hidden post that
  its author had edited (which made it visible again) is hidden again, and the
  edit becomes the content "Mark as OK" restores. The 1.1.4 upgrade had
  recorded the author's text as if it were the placeholder.
- Report reason names are escaped in the "reason in use" message and the
  delete confirmation.
- Behat tests for the report link, its position, the report dialog and
  own-post handling.
