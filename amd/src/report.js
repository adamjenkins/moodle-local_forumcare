/* eslint-disable */
// NOTE: this is the compiled AMD form, not ES6 import/export source. The
// test site this plugin is verified against runs with $CFG->debug set to
// DEBUG_DEVELOPER, which forces jsrev=-1, under which Moodle's
// lib/requirejs.php serves amd/src/*.js to the browser AS-IS rather than
// amd/build/*.min.js - and RequireJS cannot parse ES6 import/export when a
// file is served that way. Since this sandbox also has no working
// Moodle grunt/rollup toolchain to produce a verified matching pair from
// real ES6 source (see CHANGELOG/PR notes), amd/src and amd/build are kept
// as identical compiled copies so the module loads correctly here. If you
// have a real Moodle dev environment with grunt available, you can rewrite
// this as proper ES6 source and run `grunt amd` to regenerate the build.
define("local_forumcare/report", ["exports", "core/modal_save_cancel", "core/modal_events", "core/ajax", "core/str", "core/notification", "core/templates"], function (_exports, _modal_save_cancel, ModalEvents, _ajax, _str, _notification, _templates) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.init = init;
  _modal_save_cancel = _interopRequireDefault(_modal_save_cancel);
  ModalEvents = _interopRequireWildcard(ModalEvents);
  _ajax = _interopRequireDefault(_ajax);
  _notification = _interopRequireDefault(_notification);
  _templates = _interopRequireDefault(_templates);
  function _interopRequireDefault(obj) { return obj && obj.__esModule ? obj : { default: obj }; }
  function _interopRequireWildcard(obj) {
    if (obj && obj.__esModule) { return obj; }
    if (obj === null || typeof obj !== "object" && typeof obj !== "function") { return { default: obj }; }
    var newObj = {};
    for (var key in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, key)) { newObj[key] = obj[key]; }
    }
    newObj.default = obj;
    return newObj;
  }

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
  const SELECTORS = {
    ACTIONS_CONTAINER: '[data-region="post-actions-container"]',
    REPORT_LINK: '[data-action="forumcare-report"]',
    REASON_SELECT: '[data-region="forumcare-reason"]',
    COMMENT_FIELD: '[data-region="forumcare-comment"]'
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
      reasonsPromise = _ajax.default.call([{
        methodname: 'local_forumcare_get_reasons',
        args: {
          courseid: courseId
        }
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
  const getPostIdFromContainer = container => {
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
    (0, _str.get_string)('reportthispost', 'local_forumcare').then(str => {
      link.textContent = str;
      return str;
    }).catch(_notification.default.exception);
    container.appendChild(link);
  };

  /**
   * Append an unlinked "Post reported" label to a post's action menu container.
   *
   * @param {HTMLElement} container
   */
  const appendReportedLabel = container => {
    const label = document.createElement('span');
    label.setAttribute('data-region', 'post-action');
    label.setAttribute('class', 'btn btn-link disabled text-muted');
    label.setAttribute('role', 'menuitem');
    label.setAttribute('aria-disabled', 'true');
    (0, _str.get_string)('postreported', 'local_forumcare').then(str => {
      label.textContent = str;
      return str;
    }).catch(_notification.default.exception);
    container.appendChild(label);
  };

  /**
   * Scan the page for post action-menu containers not yet processed, and for
   * each one append either the report link, a "Post reported" label, or
   * nothing at all (the viewer's own post).
   */
  const injectAllLinks = () => {
    const containers = [];
    document.querySelectorAll(SELECTORS.ACTIONS_CONTAINER).forEach(container => {
      if (!container.dataset.forumcareInjected) {
        containers.push(container);
      }
    });
    if (!containers.length) {
      return;
    }
    // Mark immediately so a MutationObserver firing again while the AJAX
    // call below is in flight doesn't queue these containers a second time.
    containers.forEach(container => {
      container.dataset.forumcareInjected = '1';
    });
    const postIds = containers.map(getPostIdFromContainer).filter(Boolean);
    if (!postIds.length) {
      return;
    }
    _ajax.default.call([{
      methodname: 'local_forumcare_get_post_report_status',
      args: {
        postids: postIds
      }
    }])[0].then(statuses => {
      const byPostId = {};
      statuses.forEach(status => {
        byPostId[status.postid] = status;
      });
      containers.forEach(container => {
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
    }).catch(_notification.default.exception);
  };

  /**
   * Open the report modal for a given post id and wire up submission.
   *
   * @param {Number} postId
   */
  const openReportModal = postId => {
    getReasons().then(reasons => Promise.all([_modal_save_cancel.default.create({
      title: (0, _str.get_string)('reportpost', 'local_forumcare'),
      buttons: {
        save: (0, _str.get_string)('reportpost', 'local_forumcare')
      },
      removeOnClose: true
    }), _templates.default.render('local_forumcare/report_modal_body', {
      reasons
    })])).then(([modal, html]) => {
      modal.setBody(html);
      return modal;
    }).then(modal => {
      modal.show();
      modal.getRoot().on(ModalEvents.save, e => {
        e.preventDefault();
        const root = modal.getRoot()[0];
        const reasonId = parseInt(root.querySelector(SELECTORS.REASON_SELECT).value, 10);
        const comment = root.querySelector(SELECTORS.COMMENT_FIELD).value;
        _ajax.default.call([{
          methodname: 'local_forumcare_submit_report',
          args: {
            postid: postId,
            reasonid: reasonId,
            comment
          }
        }])[0].then(() => {
          modal.hide();
          return (0, _str.get_string)('reportsubmitted', 'local_forumcare');
        }).then(message => {
          return _notification.default.addNotification({
            message,
            type: 'success'
          });
        }).catch(_notification.default.exception);
      });
      modal.getRoot().on(ModalEvents.hidden, () => {
        modal.destroy();
      });
      return modal;
    }).catch(_notification.default.exception);
  };

  /**
   * Initialise the module: inject report links/labels into currently-rendered
   * posts, watch for dynamically-loaded posts (e.g. lazy-loaded nested
   * replies), and wire up the click handler for opening the report modal.
   *
   * @param {Number} initCourseId The id of the course being viewed.
   */
  function init(initCourseId) {
    courseId = initCourseId;
    injectAllLinks();
    const observer = new MutationObserver(() => injectAllLinks());
    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
    document.addEventListener('click', e => {
      const link = e.target.closest(SELECTORS.REPORT_LINK);
      if (!link) {
        return;
      }
      e.preventDefault();
      const postId = parseInt(link.dataset.postId, 10);
      openReportModal(postId);
    });
  }
});

//# sourceMappingURL=report.min.js.map
