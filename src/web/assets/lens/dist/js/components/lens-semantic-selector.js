/**
 * Lens Plugin - Search enhancement badge
 *
 * Server-side event (`Search::EVENT_AFTER_SEARCH`) merges Lens BM25 matches
 * into Craft's native search results. This file does one thing: drop a
 * small Lens logo next to the native search bar anywhere Craft shows an
 * asset index — picker modals and the `/admin/assets` browser page. No
 * input replacement, no event handlers, no endpoint calls.
 */
(function() {
    'use strict';

    if (!window.Craft) {
        return;
    }

    function insertBadge($container) {
        if (!$container || !$container.length) {
            return;
        }
        if ($container.prev('[data-lens-target="search-badge"]').length) {
            return;
        }

        $('<span/>', {
            'class': 'lens-search-badge',
            'title': Craft.t('lens', 'Search enhanced by Lens AI'),
            'aria-label': Craft.t('lens', 'Search enhanced by Lens AI'),
            'data-lens-target': 'search-badge'
        }).insertBefore($container);
    }

    if (Craft.AssetSelectInput) {
        var _originalCreateModal = Craft.AssetSelectInput.prototype.createModal;

        Craft.AssetSelectInput.prototype.createModal = function() {
            var modal = _originalCreateModal.call(this);
            if (!modal || modal._lensBadged) {
                return modal;
            }

            modal._lensBadged = true;

            if (typeof modal.updateSelectBtnState !== 'function') {
                return modal;
            }

            var originalUpdateState = modal.updateSelectBtnState;
            modal.updateSelectBtnState = function() {
                originalUpdateState.call(this);

                var ei = this.elementIndex;
                insertBadge(ei && ei.$searchContainer);
            };

            return modal;
        };
    }

    if (Craft.AssetIndex) {
        var _originalAfterInit = Craft.AssetIndex.prototype.afterInit;

        Craft.AssetIndex.prototype.afterInit = function() {
            if (typeof _originalAfterInit === 'function') {
                _originalAfterInit.apply(this, arguments);
            }
            insertBadge(this.$searchContainer);
        };
    }
})();
