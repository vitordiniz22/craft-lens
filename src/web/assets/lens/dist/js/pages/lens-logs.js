/**
 * Lens Plugin - Logs Page
 * Handles log detail toggle and delete-all confirmation.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var DOM = window.Lens.core.DOM;

    var LensLogsPage = {
        _initialized: false,

        init: function() {
            if (this._initialized) return;

            this._bindDetailToggle();
            this._bindDeleteAll();
            this._initialized = true;
        },

        // ================================================================
        // Detail row toggle
        // ================================================================

        _bindDetailToggle: function() {
            DOM.delegate('[data-lens-action="toggle-log-detail"]', 'click', function(e, btn) {
                var targetId = btn.dataset.target;
                if (!targetId) return;

                var row = document.getElementById(targetId);
                if (row) {
                    row.style.display = row.style.display === 'none' ? '' : 'none';
                }
            });
        },

        // ================================================================
        // Delete All confirmation
        // ================================================================

        _bindDeleteAll: function() {
            DOM.delegate('[data-lens-action="delete-all-logs"]', 'click', function(e) {
                if (!confirm(Craft.t('lens', 'Are you sure you want to delete all logs?'))) {
                    e.preventDefault();
                }
            });
        }
    };

    window.Lens.pages.LogsPage = LensLogsPage;

    function init() {
        LensLogsPage.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
