<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Admin settings for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_forumcare', get_string('pluginname', 'local_forumcare'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_forumcare/enabled',
        get_string('settings:enabled', 'local_forumcare'),
        get_string('settings:enabled_desc', 'local_forumcare'),
        1
    ));

    $settings->add(new admin_setting_heading(
        'local_forumcare/thresholdsheading',
        get_string('settings:thresholds', 'local_forumcare'),
        get_string('settings:thresholds_desc', 'local_forumcare')
    ));

    $settings->add(new admin_setting_configtext(
        'local_forumcare/threshold_hide',
        get_string('settings:thresholdhide', 'local_forumcare'),
        get_string('settings:thresholdhide_desc', 'local_forumcare'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_forumcare/threshold_suspend',
        get_string('settings:thresholdsuspend', 'local_forumcare'),
        get_string('settings:thresholdsuspend_desc', 'local_forumcare'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_forumcare/threshold_suspend_sitewide',
        get_string('settings:thresholdsuspendsitewide', 'local_forumcare'),
        get_string('settings:thresholdsuspendsitewide_desc', 'local_forumcare'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_forumcare/threshold_frivolous',
        get_string('settings:thresholdfrivolous', 'local_forumcare'),
        get_string('settings:thresholdfrivolous_desc', 'local_forumcare'),
        3,
        PARAM_INT
    ));

    $reasonsurl = new moodle_url('/local/forumcare/manage_reasons.php');
    $settings->add(new admin_setting_heading(
        'local_forumcare/reasonsheading',
        get_string('settings:managereasons', 'local_forumcare'),
        get_string('settings:managereasons_desc', 'local_forumcare') .
            ' <a href="' . $reasonsurl->out() . '">' . get_string('reasons', 'local_forumcare') . '</a>'
    ));
}
