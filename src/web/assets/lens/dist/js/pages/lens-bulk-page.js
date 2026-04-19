/**
 * Lens Plugin - Bulk Page
 * Handles the volume selector navigation on the bulk ready state.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var DOM = window.Lens.core.DOM;

    var LensBulkPage = {
        _initialized: false,

        init: function() {
            if (this._initialized) return;
            if (!this._shouldInit()) return;
            this._bindEvents();
            this._initialized = true;
        },

        _shouldInit: function() {
            return document.querySelector('[data-lens-control="bulk-volume-select"]') !== null ||
                   document.querySelector('[data-lens-action="bulk-submit"]') !== null ||
                   document.querySelector('[data-lens-action="bulk-cancel"]') !== null;
        },

        _bindEvents: function() {
            DOM.delegate('[data-lens-control="bulk-volume-select"]', 'change', function(e, select) {
                var params = select.value ? { volumeId: select.value } : {};

                // On complete state, dismiss the session first so the user
                // lands on the ready state for the new volume scope.
                if (select.dataset.lensState === 'complete') {
                    window.location.href = Craft.getCpUrl('lens/bulk/dismiss', params);
                } else {
                    window.location.href = Craft.getCpUrl('lens/bulk', params);
                }
            });

            // Cancel processing — confirm before submitting
            DOM.delegate('[data-lens-action="bulk-cancel"]', 'click', function(e, btn) {
                e.preventDefault();
                if (!confirm(Craft.t('lens', 'Cancel processing? Already-analyzed images will be kept, but remaining queued work will be removed.'))) {
                    return;
                }
                var form = btn.closest('[data-lens-target="cancel-form"]');
                if (form) {
                    form.submit();
                }
            });

            DOM.delegate('[data-lens-action="bulk-submit"]', 'click', function(e, btn) {
                setTimeout(function() {
                    btn.classList.add('loading');
                    btn.disabled = true;
                }, 0);
            });
        }
    };

    window.Lens.pages.BulkPage = LensBulkPage;

    Lens.utils.onReady(function() {
        LensBulkPage.init();
    });
})();
