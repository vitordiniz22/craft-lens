/**
 * Lens Plugin - Base Module
 * Shared utilities and dismissible notices
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    // ==========================================================================
    // Shared Utilities
    // ==========================================================================

    window.Lens.utils = {
        HIGH_CONFIDENCE_THRESHOLD: 0.8,

        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        },

        escapeAttr: function(text) {
            if (!text) return '';
            return String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },

        formatFileSize: function(bytes) {
            if (!bytes) return '';
            var units = ['B', 'KB', 'MB', 'GB'];
            var i = 0;
            var size = bytes;
            while (size >= 1024 && i < units.length - 1) {
                size /= 1024;
                i++;
            }
            return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
        },

        renderConfidenceBadge: function(confidence) {
            if (confidence === null || confidence === undefined) return '';
            var pct = Math.round(confidence * 100);
            var cls = 'lens-badge--error';
            if (confidence >= 0.8) cls = 'lens-badge--success';
            else if (confidence >= 0.5) cls = 'lens-badge--warning';
            return '<span class="lens-badge lens-badge--small ' + cls + '">' + pct + '%</span>';
        }
    };

    // ==========================================================================
    // Dismissible Notices
    // ==========================================================================

    var LensDismissibleNotices = {
        STORAGE_KEY: 'lens_dismissed_notices',

        init: function() {
            this.restoreDismissedState();
            this.initDismissButtons();
        },

        getDismissedNotices: function() {
            try {
                var stored = localStorage.getItem(this.STORAGE_KEY);
                return stored ? JSON.parse(stored) : [];
            } catch (e) {
                return [];
            }
        },

        saveDismissedNotices: function(dismissed) {
            try {
                localStorage.setItem(this.STORAGE_KEY, JSON.stringify(dismissed));
            } catch (e) {
                // localStorage not available
            }
        },

        restoreDismissedState: function() {
            var dismissed = this.getDismissedNotices();
            if (dismissed.length === 0) return;

            document.querySelectorAll('[data-lens-dismissible]').forEach(function(notice) {
                var key = notice.dataset.lensDismissible;
                if (key && dismissed.includes(key)) {
                    notice.style.display = 'none';
                }
            });
        },

        initDismissButtons: function() {
            var self = this;

            document.addEventListener('click', function(e) {
                var dismissBtn = e.target.closest('[data-lens-dismiss]');
                if (!dismissBtn) return;

                var notice = dismissBtn.closest('[data-lens-dismissible]');
                if (!notice) return;

                var key = notice.dataset.lensDismissible;

                // Hide the notice
                notice.style.display = 'none';

                // Store dismissed state if key is provided
                if (key) {
                    var dismissed = self.getDismissedNotices();
                    if (!dismissed.includes(key)) {
                        dismissed.push(key);
                        self.saveDismissedNotices(dismissed);
                    }
                }
            });
        },

        // Allow resetting dismissed notices (useful for testing or settings)
        resetDismissed: function(key) {
            if (key) {
                var dismissed = this.getDismissedNotices();
                dismissed = dismissed.filter(function(k) { return k !== key; });
                this.saveDismissedNotices(dismissed);
            } else {
                this.saveDismissedNotices([]);
            }
        }
    };

    window.Lens.DismissibleNotices = LensDismissibleNotices;

    // ==========================================================================
    // Initialize when DOM is ready
    // ==========================================================================

    function init() {
        LensDismissibleNotices.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
