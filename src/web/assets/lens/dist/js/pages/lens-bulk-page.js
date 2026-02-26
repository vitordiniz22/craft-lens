/**
 * Lens Plugin - Bulk Page
 * Handles the volume selector navigation on the bulk ready state.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var DOM = window.Lens.core.DOM;

    DOM.delegate('[data-lens-control="bulk-volume-select"]', 'change', function(e, select) {
        var params = select.value ? { volumeId: select.value } : {};
        window.location.href = Craft.getCpUrl('lens/bulk', params);
    });
})();
