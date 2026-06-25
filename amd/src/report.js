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
 * Injects a "Report this post" action into every forum post's action menu
 * (except the viewer's own posts, and posts already reported by them, which
 * get an unlinked "Post reported" label instead) and handles the report
 * submission modal.
 *
 * mod_forum has no hook/callback for adding per-post action menu items, so
 * this module finds the existing action-menu containers in the rendered
 * page and appends a link to them, mirroring the markup of the existing
 * action items (see mod_forum/templates/forum_discussion_post.mustache).
 *
 * @module     local_forumcare/report
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import * as ModalEvents from 'core/modal_events';
import Ajax from 'core/ajax';
import {get_string as getString} from 'core/str';
import Notification from 'core/notification';
import Templates from 'core/templates';

const SELECTORS = {
    ACTIONS_CONTAINER: '[data-region="post-actions-container"]',
    REPORT_LINK: '[data-action="forumcare-report"]',
    REASON_SELECT: '[data-region="forumcare-reason"]',
    COMMENT_FIELD: '[data-region="forumcare-comment"]',
};

let courseId = 0;
let reasonsPromise = null;

/**
 * Fetch the enabled report reasons for the current course once and cache the promise.
 *
 * @return {Promise}
 */
const getReasons = () => {
    if (!reasonsPromise) {
        reasonsPromise = Ajax.call([{
            methodname: 'local_forumcare_get_reasons',
            args: {courseid: courseId},
        }])[0];
    }
    return reasonsPromise;
};

/**
 * Extract the post id from a post-actions-container's aria-controls attribute.
 *
 * @param {HTMLElement} container
 * @return {Number}
 */
const getPostIdFromContainer = (container) => {
    const ariaControls = container.getAttribute('aria-controls') || '';
    return parseInt(ariaControls.replace(/^p/, ''), 10) || 0;
};

/**
 * Append the "Report this post" link to a post's action menu container.
 *
 * @param {HTMLElement} container
 * @param {Number} postId
 */
const appendReportLink = (container, postId) => {
    const link = document.createElement('a');
    link.setAttribute('data-region', 'post-action');
    link.setAttribute('data-action', 'forumcare-report');
    link.setAttribute('data-post-id', postId);
    link.setAttribute('href', '#');
    link.setAttribute('class', 'btn btn-link');
    link.setAttribute('role', 'menuitem');

    getString('reportthispost', 'local_forumcare').then((str) => {
        link.textContent = str;
        return str;
    }).catch(Notification.exception);

    container.appendChild(link);
};

/**
 * Append an unlinked "Post reported" label to a post's action menu container.
 *
 * @param {HTMLElement} container
 */
const appendReportedLabel = (container) => {
    const label = document.createElement('span');
    label.setAttribute('data-region', 'post-action');
    label.setAttribute('class', 'btn btn-link disabled text-muted');
    label.setAttribute('role', 'menuitem');
    label.setAttribute('aria-disabled', 'true');

    getString('postreported', 'local_forumcare').then((str) => {
        label.textContent = str;
        return str;
    }).catch(Notification.exception);

    container.appendChild(label);
};

/**
 * Scan the page for post action-menu containers not yet processed, and for
 * each one append either the report link, a "Post reported" label, or
 * nothing at all (the viewer's own post).
 */
const injectAllLinks = () => {
    const containers = [];
    document.querySelectorAll(SELECTORS.ACTIONS_CONTAINER).forEach((container) => {
        if (!container.dataset.forumcareInjected) {
            containers.push(container);
        }
    });
    if (!containers.length) {
        return;
    }
    // Mark immediately so a MutationObserver firing again while the AJAX
    // call below is in flight doesn't queue these containers a second time.
    containers.forEach((container) => {
        container.dataset.forumcareInjected = '1';
    });

    const postIds = containers.map(getPostIdFromContainer).filter(Boolean);
    if (!postIds.length) {
        return;
    }

    Ajax.call([{
        methodname: 'local_forumcare_get_post_report_status',
        args: {postids: postIds},
    }])[0].then((statuses) => {
        const byPostId = {};
        statuses.forEach((status) => {
            byPostId[status.postid] = status;
        });

        containers.forEach((container) => {
            const postId = getPostIdFromContainer(container);
            const status = byPostId[postId];
            if (!postId || !status || status.isown) {
                return;
            }
            if (status.reported) {
                appendReportedLabel(container);
            } else {
                appendReportLink(container, postId);
            }
        });
        return statuses;
    }).catch(Notification.exception);
};

/**
 * Submit the report form inside a given modal and show a confirmation.
 *
 * @param {Object} modal
 * @param {Number} postId
 */
const submitReportForm = (modal, postId) => {
    const root = modal.getRoot()[0];
    const reasonId = parseInt(root.querySelector(SELECTORS.REASON_SELECT).value, 10);
    const comment = root.querySelector(SELECTORS.COMMENT_FIELD).value;

    Ajax.call([{
        methodname: 'local_forumcare_submit_report',
        args: {postid: postId, reasonid: reasonId, comment},
    }])[0].then(() => {
        modal.hide();
        return getString('reportsubmitted', 'local_forumcare');
    }).then((message) => {
        return Notification.addNotification({message, type: 'success'});
    }).catch(Notification.exception);
};

/**
 * Open the report modal for a given post id and wire up submission.
 *
 * @param {Number} postId
 */
const openReportModal = (postId) => {
    getReasons().then((reasons) => Promise.all([
        ModalSaveCancel.create({
            title: getString('reportpost', 'local_forumcare'),
            buttons: {save: getString('reportpost', 'local_forumcare')},
            removeOnClose: true,
        }),
        Templates.render('local_forumcare/report_modal_body', {reasons}),
    ])).then(([modal, html]) => {
        modal.setBody(html);
        return modal;
    }).then((modal) => {
        modal.show();

        modal.getRoot().on(ModalEvents.save, (e) => {
            e.preventDefault();
            submitReportForm(modal, postId);
        });

        modal.getRoot().on(ModalEvents.hidden, () => {
            modal.destroy();
        });

        return modal;
    }).catch(Notification.exception);
};

/**
 * Initialise the module: inject report links/labels into currently-rendered
 * posts, watch for dynamically-loaded posts (e.g. lazy-loaded nested
 * replies), and wire up the click handler for opening the report modal.
 *
 * @param {Number} initCourseId The id of the course being viewed.
 */
export const init = (initCourseId) => {
    courseId = initCourseId;
    injectAllLinks();

    const observer = new MutationObserver(() => injectAllLinks());
    observer.observe(document.body, {childList: true, subtree: true});

    document.addEventListener('click', (e) => {
        const link = e.target.closest(SELECTORS.REPORT_LINK);
        if (!link) {
            return;
        }
        e.preventDefault();
        const postId = parseInt(link.dataset.postId, 10);
        openReportModal(postId);
    });
};
