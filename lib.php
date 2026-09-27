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
 * Library callbacks for local_forumcare.
 *
 * @package    local_forumcare
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Whether the given activity settings form is mod_forum's own form.
 *
 * The coursemodule_* form callbacks run for every activity's settings form,
 * and the form's current data is whatever the caller passed to the
 * constructor: modedit.php always sets modulename, but other callers (other
 * plugins' tests, custom scripts) may not. So never assume the property
 * exists; also accept the forum's own form class, which moodleform_mod
 * requires to be named mod_<modname>_mod_form.
 *
 * @param \moodleform_mod $formwrapper
 * @return bool
 */
function local_forumcare_is_forum_form($formwrapper): bool {
    if ($formwrapper instanceof \mod_forum_mod_form) {
        return true;
    }
    $current = $formwrapper->get_current();
    return is_object($current) && ($current->modulename ?? '') === 'forum';
}

/**
 * Add a "Forum care" section to the forum's own activity settings
 * (edit-settings) form. Real generic hook dispatched by
 * moodleform_mod::definition() via get_plugins_with_function(), callable
 * by any plugin component (not just mod_* or the activity's own type).
 *
 * @param \moodleform_mod $formwrapper
 * @param \MoodleQuickForm $mform
 * @return void
 */
function local_forumcare_coursemodule_standard_elements($formwrapper, $mform) {
    if (!local_forumcare_is_forum_form($formwrapper)) {
        return;
    }

    if (!has_capability('moodle/course:manageactivities', $formwrapper->get_context())) {
        return;
    }

    $mform->addElement('header', 'forumcare_header', get_string('pluginname', 'local_forumcare'));
    $mform->setExpanded('forumcare_header', false);

    $mform->addElement('advcheckbox', 'forumcare_enabled', get_string('enableforumcare', 'local_forumcare'));

    $thresholdlangkeys = [
        'threshold_hide' => 'settings:thresholdhide',
        'threshold_suspend' => 'settings:thresholdsuspend',
        'threshold_frivolous' => 'settings:thresholdfrivolous',
    ];

    foreach (\local_forumcare\local\helper::PER_FORUM_THRESHOLDS as $name) {
        $mform->addElement('text', 'forumcare_' . $name, get_string($thresholdlangkeys[$name], 'local_forumcare'));
        $mform->setType('forumcare_' . $name, PARAM_INT);
        $mform->addRule('forumcare_' . $name, null, 'numeric', null, 'client');
        $mform->addHelpButton('forumcare_' . $name, 'forumthresholdoverride', 'local_forumcare');
        // Covers the "new activity" case, where there is no course module yet
        // for coursemodule_definition_after_data() to load real values from.
        $mform->setDefault('forumcare_' . $name, (int) get_config('local_forumcare', $name));
    }
}

/**
 * Populate the forum care fields with this forum's current effective
 * settings when editing an existing forum instance.
 *
 * @param \moodleform_mod $formwrapper
 * @param \MoodleQuickForm $mform
 * @return void
 */
function local_forumcare_coursemodule_definition_after_data($formwrapper, $mform) {
    if (!local_forumcare_is_forum_form($formwrapper)) {
        return;
    }

    $cm = $formwrapper->get_coursemodule();
    if (!$cm) {
        // New activity being created; defaults were already set in coursemodule_standard_elements().
        return;
    }

    if (!$mform->elementExists('forumcare_enabled')) {
        return;
    }

    $mform->setDefault('forumcare_enabled', \local_forumcare\local\helper::is_enabled_for_forum($cm->instance) ? 1 : 0);
    foreach (\local_forumcare\local\helper::PER_FORUM_THRESHOLDS as $name) {
        $mform->setDefault('forumcare_' . $name, \local_forumcare\local\helper::get_threshold($cm->instance, $name));
    }
}

/**
 * Persist the forum care fields submitted via the forum's own edit-settings
 * form into our own table (forum has no column for this, and we can't add
 * one to mod_forum's schema).
 *
 * @param \stdClass $moduleinfo
 * @param \stdClass $course
 * @return \stdClass
 */
function local_forumcare_coursemodule_edit_post_actions($moduleinfo, $course) {
    if (($moduleinfo->modulename ?? '') !== 'forum') {
        return $moduleinfo;
    }

    // The forumcare_* fields only exist when the form actually went through
    // our coursemodule_standard_elements() section. Code paths that create or
    // update a forum without using that form (data generators, CLI scripts,
    // web service calls, etc.) must leave any existing forum care settings
    // untouched rather than silently overwriting them with defaults of 0.
    if (!isset($moduleinfo->forumcare_enabled)) {
        return $moduleinfo;
    }

    \local_forumcare\local\helper::set_forum_enabled($moduleinfo->instance, !empty($moduleinfo->forumcare_enabled));

    $thresholds = [];
    foreach (\local_forumcare\local\helper::PER_FORUM_THRESHOLDS as $name) {
        $thresholds[$name] = (int) ($moduleinfo->{'forumcare_' . $name} ?? 0);
    }
    \local_forumcare\local\helper::set_forum_thresholds($moduleinfo->instance, $thresholds);

    return $moduleinfo;
}

/**
 * Add a "Forum care" link to the course administration navigation for
 * users who can review reports in that course.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_forumcare_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('local/forumcare:reviewreports', $context)) {
        $url = new moodle_url('/local/forumcare/report.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('navreviewreports', 'local_forumcare'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'forumcarereports'
        );
    }

    if (has_capability('local/forumcare:managecoursereasons', $context)) {
        $url = new moodle_url('/local/forumcare/manage_reasons.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('navmanagereasons', 'local_forumcare'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'forumcarereasons'
        );
    }
}
