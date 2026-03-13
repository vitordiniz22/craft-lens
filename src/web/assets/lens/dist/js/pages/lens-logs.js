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
                var targetId = btn.dataset.lensDetailId;
                if (!targetId) return;

                var row = document.querySelector('[data-lens-detail-id="' + targetId + '"]');
                if (row) {
                    var isHidden = row.hidden || window.getComputedStyle(row).display === 'none';
                    if (isHidden) {
                        row.style.display = 'table-row';
                        row.hidden = false;
                    } else {
                        DOM.hide(row);
                    }
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

    Lens.utils.onReady(function() {
        LensLogsPage.init();
    });
})();
