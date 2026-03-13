/**
 * Lens Plugin - Enhanced Asset Search
 *
 * Patches Craft.AssetSelectInput.prototype.createModal to replace
 * the native search bar in asset selector modals with Lens metadata search.
 *
 * Loaded globally on all CP pages when the "Enhanced Asset Search" setting is enabled.
 * No custom field type needed — all native Assets fields are enhanced automatically.
 *
 * Defensive design: Every reference to Craft internals ($search, $searchContainer,
 * $clearSearchBtn, $toolbar) is guarded. If Craft renames or removes any of these,
 * the worst case is both search bars appear — the modal never breaks.
 *
 * NOTE: This file patches Craft internals and intentionally uses jQuery + class
 * selectors to match Craft's modal DOM structure. The data-lens-* convention
 * applies only to Lens-owned elements injected into the modal.
 */
(function() {
    'use strict';

    if (!Craft || !Craft.AssetSelectInput) {
        return;
    }

    var _config = window.Lens && window.Lens.config;
    var SEARCH_ACTION = 'lens/semantic-search/search';
    var DEBOUNCE_MS = _config
        ? _config.POLLING.SEMANTIC_SEARCH_DEBOUNCE_MS
        : 350;
    var SEARCH_LIMIT = _config ? _config.SEARCH.MODAL_LIMIT : 50;
    var MIN_QUERY_LENGTH = _config ? _config.SEARCH.MIN_QUERY_LENGTH : 2;

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
            clearBtn: null,
            searchTimeout: null,
            searchActive: false,
            loading: false,
            nativeHidden: false,
            originalCriteriaId: undefined
        };

        // Hook into updateSelectBtnState — called after _createElementIndex
        // completes its AJAX and the element index is fully ready
        if (typeof modal.updateSelectBtnState === 'function') {
            var originalUpdateState = modal.updateSelectBtnState;

            modal.updateSelectBtnState = function() {
                originalUpdateState.call(this);

                if (!this._lens.searchBar && this.elementIndex) {
                    _injectLensSearch(this);
                }
            };
        }

        return modal;
    };

    /**
     * Inject the Lens search into the modal.
     * Replaces the native input inside the search container so the magnifier icon,
     * clear button (X), and filter button stay in their native positions.
     * If the search container isn't found, falls back to toolbar or modal body.
     */
    function _injectLensSearch(modal) {
        var lens = modal._lens;
        var ei = modal.elementIndex;
        var $searchContainer = ei.$searchContainer;

        // Lens label — sits before the search container in the toolbar
        var $label = $('<div/>', {
            'class': 'lens-semantic-search__label',
            'data-lens-target': 'semantic-search'
        });

        $('<span/>', {
            'class': 'lens-semantic-search__icon',
            'aria-hidden': 'true'
        }).appendTo($label);

        $('<span/>', {
            text: 'Lens'
        }).appendTo($label);

        // Search input — inserted directly into the texticon container
        // so the native magnifier icon overlays it
        lens.searchInput = $('<input/>', {
            type: 'text',
            'class': 'text fullwidth clearable',
            placeholder: Craft.t('lens', 'Search by description, tags, content...'),
            autocomplete: 'off',
            'data-lens-control': 'semantic-search-input'
        });

        // Clear button — matches native clear-btn style (X overlaying the input)
        lens.clearBtn = $('<button/>', {
            type: 'button',
            'class': 'clear-btn hidden',
            title: Craft.t('lens', 'Clear search'),
            'aria-label': Craft.t('lens', 'Clear search'),
            'data-lens-action': 'semantic-search-clear'
        });

        // Insert elements into the DOM
        var inserted = false;

        if ($searchContainer && $searchContainer.length) {
            // Hide native search input and clear button (defensive — skip if missing)
            if (ei.$search && typeof ei.$search.addClass === 'function') {
                ei.$search.addClass('hidden');
                lens.nativeHidden = true;
            }
            if (ei.$clearSearchBtn && typeof ei.$clearSearchBtn.addClass === 'function') {
                ei.$clearSearchBtn.addClass('hidden');
            }

            // Place label before the search container in the toolbar
            $label.insertBefore($searchContainer);

            // Insert our input directly into the texticon container (after the magnifier icon)
            var $magnifier = $searchContainer.find('.texticon-icon.search');
            if ($magnifier.length) {
                lens.searchInput.insertAfter($magnifier);
            } else {
                lens.searchInput.prependTo($searchContainer);
            }

            // Insert our clear button — same position as the native one (before filter btn)
            var $filterBtn = $searchContainer.find('.filter-btn');
            if ($filterBtn.length) {
                lens.clearBtn.insertBefore($filterBtn);
            } else {
                lens.clearBtn.appendTo($searchContainer);
            }

            inserted = true;
        }

        // Fallback: wrap everything in a div if search container not found
        if (!inserted) {
            var $fallback = $('<div/>', {
                'class': 'lens-semantic-search',
                'data-lens-target': 'semantic-search'
            });
            $label.appendTo($fallback);
            lens.searchInput.appendTo($fallback);
            lens.clearBtn.appendTo($fallback);

            if (ei.$toolbar && ei.$toolbar.length) {
                $fallback.appendTo(ei.$toolbar);
                inserted = true;
            } else if (modal.$body && modal.$body.length) {
                $fallback.prependTo(modal.$body);
                inserted = true;
            }
        }

        if (!inserted) {
            return;
        }

        lens.searchBar = $label;

        _bindEvents(modal);

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
     * Execute search via Lens endpoint.
     */
    function _executeSearch(modal, query) {
        var lens = modal._lens;

        lens.loading = true;
        lens.searchInput.addClass('loading');
        lens.clearBtn.addClass('hidden');

        Craft.sendActionRequest('POST', SEARCH_ACTION, {
            data: {
                query: query,
                limit: SEARCH_LIMIT
            }
        }).then(function(response) {
            var data = response.data;

            lens.loading = false;
            lens.searchInput.removeClass('loading');
            lens.clearBtn.removeClass('hidden');
            lens.searchActive = true;

            if (data.assetIds && data.assetIds.length > 0) {
                _applyFilter(modal, data.assetIds);
            } else {
                _applyFilter(modal, [0]);
            }
        }).catch(function() {
            lens.loading = false;
            lens.searchInput.removeClass('loading');
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
        if (!modal.elementIndex || !modal.elementIndex.settings || !modal.elementIndex.settings.criteria) {
            return;
        }

        var lens = modal._lens;

        if (lens.originalCriteriaId === undefined) {
            lens.originalCriteriaId = modal.elementIndex.settings.criteria.id || null;
        }

        modal.elementIndex.settings.criteria.id = assetIds;

        if (typeof modal.elementIndex.updateElements === 'function') {
            modal.elementIndex.updateElements();
        }
    }

    /**
     * Clear the search filter and restore the original view.
     */
    function _clearSearch(modal) {
        var lens = modal._lens;

        if (!lens.searchActive) {
            return;
        }

        lens.searchActive = false;
        lens.clearBtn.addClass('hidden');

        if (modal.elementIndex && modal.elementIndex.settings && modal.elementIndex.settings.criteria) {
            if (lens.originalCriteriaId === null) {
                delete modal.elementIndex.settings.criteria.id;
            } else if (lens.originalCriteriaId !== undefined) {
                modal.elementIndex.settings.criteria.id = lens.originalCriteriaId;
            }
            lens.originalCriteriaId = undefined;

            if (typeof modal.elementIndex.updateElements === 'function') {
                modal.elementIndex.updateElements();
            }
        }
    }

})();
