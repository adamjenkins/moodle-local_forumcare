# Changes

## v1.1.3

- Declare Moodle 5.3 support.
- Site-wide suspension now uses `\core\user::update_user()` on Moodle 5.3+,
  where `user_update_user()` is deprecated; older versions keep using
  `user_update_user()`.
- Activity settings form callbacks no longer raise an "Undefined property
  $modulename" warning for other activities' forms built without it.
