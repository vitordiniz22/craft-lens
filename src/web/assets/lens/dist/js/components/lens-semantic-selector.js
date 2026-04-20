/**
 * Lens Plugin - Search enhancement badge
 *
 * Server-side event (`Search::EVENT_AFTER_SEARCH`) merges Lens BM25 matches
 * into Craft's native search results. This file does one thing: drop a
 * small Lens logo next to the native search bar in asset picker modals so
 * users see the search is AI-enhanced. No input replacement, no event
 * handlers, no endpoint calls — the modal behaves 100% native.
 */
(function() {
    'use strict';

    if (!window.Craft || !Craft.AssetSelectInput) {
        return;
    }

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

        // `updateSelectBtnState` fires after `_createElementIndex` builds
        // the toolbar DOM, and again on every sidebar source switch. The
        // idempotency guard (`.prev(...).length`) keeps us from duplicating.
        var originalUpdateState = modal.updateSelectBtnState;
        modal.updateSelectBtnState = function() {
            originalUpdateState.call(this);

            var ei = this.elementIndex;
            var $container = ei && ei.$searchContainer;
            if (!$container || !$container.length) {
                return;
            }
            if ($container.prev('.lens-search-badge').length) {
                return;
            }

            $('<span/>', {
                'class': 'lens-search-badge',
                'title': Craft.t('lens', 'Search enhanced by Lens AI'),
                'aria-label': Craft.t('lens', 'Search enhanced by Lens AI'),
                'data-lens-target': 'search-badge'
            }).insertBefore($container);
        };

        return modal;
    };
})();
