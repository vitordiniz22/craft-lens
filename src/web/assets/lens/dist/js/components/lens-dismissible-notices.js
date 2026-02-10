/**
 * Lens Plugin - Dismissible Notices Component
 * Manages persistent dismissible notices with localStorage
 * Extracted from lens-base.js
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Dismissible Notices Component
     */
    const LensDismissibleNotices = {
        _initialized: false,

        /**
         * Get storage key from config
         */
        get STORAGE_KEY() {
            return window.Lens.config.STORAGE.DISMISSED_NOTICES;
        },

        /**
         * Initialize dismissible notices
         */
        init: function() {
            if (this._initialized) return;
            this.restoreDismissedState();
            this.initDismissButtons();
            this._initialized = true;
        },

        /**
         * Get array of dismissed notice keys from localStorage
         * @returns {Array<string>} Array of dismissed notice keys
         */
        getDismissedNotices: function() {
            try {
                const stored = localStorage.getItem(this.STORAGE_KEY);
                return stored ? JSON.parse(stored) : [];
            } catch (e) {
                return [];
            }
        },

        /**
         * Save dismissed notices to localStorage
         * @param {Array<string>} dismissed - Array of dismissed notice keys
         */
        saveDismissedNotices: function(dismissed) {
            try {
                localStorage.setItem(this.STORAGE_KEY, JSON.stringify(dismissed));
            } catch (e) {
                // localStorage not available
            }
        },

        /**
         * Restore dismissed state on page load
         * Hides notices that were previously dismissed
         */
        restoreDismissedState: function() {
            const dismissed = this.getDismissedNotices();
            if (dismissed.length === 0) return;

            document.querySelectorAll('[data-lens-dismissible]').forEach(function(notice) {
                const key = notice.dataset.lensDismissible;
                if (key && dismissed.includes(key)) {
                    notice.style.display = 'none';
                }
            });
        },

        /**
         * Initialize dismiss button click handlers
         * Uses event delegation for dynamic notices
         */
        initDismissButtons: function() {
            window.Lens.core.DOM.delegate('[data-lens-dismiss]', 'click', (e, dismissBtn) => {
                const notice = dismissBtn.closest('[data-lens-dismissible]');
                if (!notice) return;

                const key = notice.dataset.lensDismissible;

                // Hide the notice
                notice.style.display = 'none';

                // Store dismissed state if key is provided
                if (key) {
                    const dismissed = this.getDismissedNotices();
                    if (!dismissed.includes(key)) {
                        dismissed.push(key);
                        this.saveDismissedNotices(dismissed);
                    }
                }
            });
        },

        /**
         * Reset dismissed notices
         * Useful for testing or settings reset
         * @param {string} [key] - Optional specific key to reset, or reset all if not provided
         */
        resetDismissed: function(key) {
            if (key) {
                let dismissed = this.getDismissedNotices();
                dismissed = dismissed.filter(function(k) { return k !== key; });
                this.saveDismissedNotices(dismissed);
            } else {
                this.saveDismissedNotices([]);
            }
        }
    };

    window.Lens.components.DismissibleNotices = LensDismissibleNotices;

    // Auto-initialize on DOM ready
    function init() {
        LensDismissibleNotices.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
