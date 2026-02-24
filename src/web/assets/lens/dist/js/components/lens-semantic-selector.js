/**
 * Lens Plugin - Semantic Asset Selector
 *
 * Patches Craft.AssetSelectInput.prototype.createModal to replace
 * the native search bar in asset selector modals with Lens semantic search.
 *
 * Loaded globally on all CP pages when the "Semantic Asset Search" setting is enabled.
 * No custom field type needed — all native Assets fields are enhanced automatically.
 */
(function() {
    'use strict';

    if (!Craft || !Craft.AssetSelectInput) {
        return;
    }

    var SEARCH_ACTION = 'lens/semantic-search/search';
    var DEBOUNCE_MS = 350;
    var SEARCH_LIMIT = 50;
    var MIN_QUERY_LENGTH = 2;

    // Store the original createModal before patching
    var _originalCreateModal = Craft.AssetSelectInput.prototype.createModal;

    Craft.AssetSelectInput.prototype.createModal = function() {
        var modal = _originalCreateModal.call(this);

        if (!modal || modal._lensEnhanced) {
            return modal;
        }

        modal._lensEnhanced = true;

        // State stored on the modal instance so each modal is independent
        modal._lens = {
            searchBar: null,
            searchInput: null,
            spinner: null,
            clearBtn: null,
            resultCount: null,
            searchTimeout: null,
            searchActive: false,
            originalCriteriaId: undefined
        };

        // Hook into updateSelectBtnState — called after _createElementIndex
        // completes its AJAX and the element index is fully ready
        var originalUpdateState = modal.updateSelectBtnState;

        modal.updateSelectBtnState = function() {
            originalUpdateState.call(this);

            if (!this._lens.searchBar && this.elementIndex) {
                _injectLensSearch(this);
            }
        };

        return modal;
    };

    /**
     * Inject the Lens search bar into the modal, replacing the native search input.
     * Keeps the filter button ($filterBtn) visible.
     */
    function _injectLensSearch(modal) {
        var lens = modal._lens;

        // Hide native search input and clear button, keep filter button
        if (modal.elementIndex.$search) {
            modal.elementIndex.$search.addClass('hidden');
        }
        if (modal.elementIndex.$clearSearchBtn) {
            modal.elementIndex.$clearSearchBtn.addClass('hidden');
        }

        // Build the Lens search bar
        var $searchBar = $('<div/>', {
            'class': 'lens-semantic-search',
            'data-lens-target': 'semantic-search'
        });

        lens.searchInput = $('<input/>', {
            type: 'text',
            'class': 'text fullwidth',
            placeholder: Craft.t('lens', 'Describe what you\'re looking for...'),
            autocomplete: 'off',
            'data-lens-control': 'semantic-search-input'
        }).appendTo($searchBar);

        lens.spinner = $('<div/>', {
            'class': 'spinner hidden'
        }).appendTo($searchBar);

        lens.clearBtn = $('<button/>', {
            type: 'button',
            'class': 'btn small hidden',
            text: Craft.t('lens', 'Clear'),
            'data-lens-action': 'semantic-search-clear'
        }).appendTo($searchBar);

        lens.resultCount = $('<div/>', {
            'class': 'lens-semantic-search__result-count hidden',
            'data-lens-target': 'semantic-result-count'
        }).appendTo($searchBar);

        // Insert into the search container (alongside the filter button)
        if (modal.elementIndex.$searchContainer) {
            $searchBar.prependTo(modal.elementIndex.$searchContainer);
        } else if (modal.elementIndex.$toolbar) {
            $searchBar.appendTo(modal.elementIndex.$toolbar);
        }

        lens.searchBar = $searchBar;

        _bindEvents(modal);

        // Focus the search input
        lens.searchInput.focus();
    }

    /**
     * Bind search input events with debounce.
     */
    function _bindEvents(modal) {
        var lens = modal._lens;

        lens.searchInput.on('input', function() {
            if (lens.searchTimeout) {
                clearTimeout(lens.searchTimeout);
            }

            var val = $(this).val().trim();

            if (val.length === 0) {
                _clearSearch(modal);
                return;
            }

            if (val.length < MIN_QUERY_LENGTH) {
                return;
            }

            lens.searchTimeout = setTimeout(function() {
                _executeSearch(modal, val);
            }, DEBOUNCE_MS);
        });

        lens.clearBtn.on('click', function() {
            lens.searchInput.val('');
            _clearSearch(modal);
            lens.searchInput.focus();
        });

        // ESC clears search without closing modal
        lens.searchInput.on('keydown', function(ev) {
            if (ev.keyCode === Garnish.ESC_KEY && lens.searchActive) {
                ev.stopPropagation();
                lens.searchInput.val('');
                _clearSearch(modal);
            }
        });
    }

    /**
     * Execute semantic search via Lens endpoint.
     */
    function _executeSearch(modal, query) {
        var lens = modal._lens;

        lens.spinner.removeClass('hidden');
        lens.clearBtn.addClass('hidden');

        Craft.sendActionRequest('POST', SEARCH_ACTION, {
            data: {
                query: query,
                limit: SEARCH_LIMIT
            }
        }).then(function(response) {
            var data = response.data;

            lens.spinner.addClass('hidden');
            lens.clearBtn.removeClass('hidden');
            lens.searchActive = true;

            if (data.assetIds && data.assetIds.length > 0) {
                _applyFilter(modal, data.assetIds);

                lens.resultCount
                    .text(Craft.t('lens', '{count} results found', {
                        count: data.total
                    }))
                    .removeClass('hidden');
            } else {
                _applyFilter(modal, [0]);

                lens.resultCount
                    .text(Craft.t('lens', 'No results found'))
                    .removeClass('hidden');
            }
        }).catch(function() {
            lens.spinner.addClass('hidden');
            lens.clearBtn.removeClass('hidden');

            Craft.cp.displayError(
                Craft.t('lens', 'Search failed. Try browsing folders instead.')
            );
        });
    }

    /**
     * Apply asset ID filter to the modal's element index.
     */
    function _applyFilter(modal, assetIds) {
        if (!modal.elementIndex) {
            return;
        }

        var lens = modal._lens;

        if (lens.originalCriteriaId === undefined) {
            lens.originalCriteriaId = modal.elementIndex.settings.criteria.id || null;
        }

        modal.elementIndex.settings.criteria.id = assetIds;
        modal.elementIndex.updateElements();
    }

    /**
     * Clear the semantic search filter and restore the original view.
     */
    function _clearSearch(modal) {
        var lens = modal._lens;

        if (!lens.searchActive) {
            return;
        }

        lens.searchActive = false;
        lens.clearBtn.addClass('hidden');
        lens.resultCount.addClass('hidden');

        if (modal.elementIndex) {
            if (lens.originalCriteriaId === null) {
                delete modal.elementIndex.settings.criteria.id;
            } else if (lens.originalCriteriaId !== undefined) {
                modal.elementIndex.settings.criteria.id = lens.originalCriteriaId;
            }
            lens.originalCriteriaId = undefined;

            modal.elementIndex.updateElements();
        }
    }

})();
