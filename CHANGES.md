# Changes

## Unreleased

- Declare Moodle 5.3 support.
- Site-wide suspension now uses `\core\user::update_user()` on Moodle 5.3+,
  where `user_update_user()` is deprecated; older versions keep using
  `user_update_user()`.
- Activity settings form callbacks no longer raise an "Undefined property
  $modulename" warning for other activities' forms built without it.

## v1.1.2

- The full GPL-3.0 licence text is now included as `LICENSE` in the repository
  root. The plugin's licence is unchanged (GPL-3.0-or-later, as declared in
  `composer.json`); the file was simply missing.
