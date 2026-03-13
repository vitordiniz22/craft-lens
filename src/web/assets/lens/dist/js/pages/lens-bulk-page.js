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
                   document.querySelector('[data-lens-action="toggle-error-group"]') !== null;
        },

        _bindEvents: function() {
            DOM.delegate('[data-lens-control="bulk-volume-select"]', 'change', function(e, select) {
                var params = select.value ? { volumeId: select.value } : {};
                window.location.href = Craft.getCpUrl('lens/bulk', params);
            });

            DOM.delegate('[data-lens-action="toggle-error-group"]', 'click', function(e, btn) {
                var group = btn.closest('[data-lens-target="error-group"]');
                if (!group) return;
                var detail = group.querySelector('[data-lens-target="error-group-detail"]');
                if (!detail) return;
                var isExpanded = !detail.hidden;
                DOM.toggle(detail, !isExpanded);
                group.classList.toggle('lens-error-group--expanded', !isExpanded);
            });
        }
    };

    window.Lens.pages.BulkPage = LensBulkPage;

    Lens.utils.onReady(function() {
        LensBulkPage.init();
    });
})();
