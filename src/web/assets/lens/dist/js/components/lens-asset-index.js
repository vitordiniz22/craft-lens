/**
 * Lens Plugin - Asset index deep-link bootstrap
 *
 * Craft's element index doesn't consume `?source=`, `?search=`, or any
 * filter key on initial page load. This file closes the gap for Lens
 * notices and chips that link to the native asset index:
 *
 *   ?source=lens:all          → select the Lens "All Images" source
 *   ?lensFilter=<key>         → apply a Lens criterion (Blurry, NSFW, etc.)
 *   ?source=lens:<key>        → same as above; the filter is derived from the
 *                               source key as a fallback when the source is
 *                               hidden via Customize Sources
 *   ?search=<text>            → pre-fill Craft's native search box
 *
 * Runs only on the asset index page. Silently no-ops elsewhere or when
 * `Craft.elementIndex` never initializes (modals, 404s, etc.).
 *
 * Safety contract: this script MUST NOT break the asset index. Every flow
 * is wrapped in try/catch; on failure we log and fall back to the native
 * experience.
 */
(function() {
    'use strict';

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    var LENS_PREFIX = 'lens:';
    var FALLBACK_SOURCE = 'lens:all';
    var CHIP_SELECTOR = '[data-lens-target="filter-chip"]';
    var MAX_WAIT_MS = 5000;
    var POLL_INTERVAL_MS = 50;

    // Filter key → AssetQueryBehavior criteria. Mirrors the source criteria
    // registered in Plugin::registerAssetSources().
    var FILTER_CRITERIA = {
        'not-analysed': {lensStatus: 'untagged'},
        'failed': {lensStatus: 'failed'},
        'missing-alt-text': {hasAlt: false},
        'missing-focal-point': {lensHasFocalPoint: false},
        'nsfw-flagged': {lensNsfwFlagged: true},
        'file-too-large': {lensTooLarge: true},
        'contains-people': {lensContainsPeople: true},
        'has-watermark': {lensHasWatermark: true},
        'has-brand-logo': {lensContainsBrandLogo: true},
        'blurry': {lensBlurry: true},
        'too-dark': {lensTooDark: true},
        'too-bright': {lensTooBright: true},
        'low-contrast': {lensLowContrast: true},
        'needs-review': {lensStatus: 'pending_review'},
    };

    var FILTER_LABELS = {
        'not-analysed': 'Not analysed',
        'failed': 'Failed analyses',
        'missing-alt-text': 'Missing alt text',
        'missing-focal-point': 'Missing focal point',
        'nsfw-flagged': 'NSFW flagged',
        'file-too-large': 'File too large',
        'contains-people': 'Contains people',
        'has-watermark': 'Watermarked',
        'has-brand-logo': 'Brand logo',
        'blurry': 'Blurry',
        'too-dark': 'Too dark',
        'too-bright': 'Too bright',
        'low-contrast': 'Low contrast',
        'needs-review': 'Needs review',
    };

    // ------------------------------------------------------------------
    // Generic helpers
    // ------------------------------------------------------------------

    function safeWarn(label, err) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('[Lens] ' + label, err);
        }
    }

    function readParams() {
        var raw = (typeof Craft.getQueryParams === 'function') ? Craft.getQueryParams() : {};
        return {
            source: raw.source || null,
            filterKey: raw.lensFilter || null,
            tag: raw.lensTag || null,
            color: raw.lensColor || null,
            search: raw.search || null,
        };
    }

    function isLensRequest(params) {
        return (params.source && params.source.indexOf(LENS_PREFIX) === 0)
            || !!params.filterKey
            || !!params.tag
            || !!params.color
            || (params.search && params.source === FALLBACK_SOURCE);
    }

    /**
     * Build the active criteria + chip label from URL params. Precedence:
     * explicit filter key → dynamic tag → dynamic color.
     */
    function resolveCriteria(params) {
        if (params.filterKey && FILTER_CRITERIA[params.filterKey]) {
            return {
                criteria: FILTER_CRITERIA[params.filterKey],
                chipPrefix: Craft.t('lens', 'Lens filter:'),
                chipLabel: FILTER_LABELS[params.filterKey] || params.filterKey,
            };
        }
        if (params.tag) {
            return {
                criteria: {lensTag: params.tag},
                chipPrefix: Craft.t('lens', 'Lens tag:'),
                chipLabel: params.tag,
            };
        }
        if (params.color) {
            return {
                criteria: {lensColor: params.color},
                chipPrefix: Craft.t('lens', 'Lens color:'),
                chipLabel: params.color,
            };
        }
        return {criteria: null, chipPrefix: null, chipLabel: null};
    }

    // Strip `lens:` prefix so a source key doubles as a filter key.
    function filterKeyFromSource(sourceKey) {
        if (!sourceKey || sourceKey.indexOf(LENS_PREFIX) !== 0) {
            return null;
        }
        var key = sourceKey.substr(LENS_PREFIX.length);
        return FILTER_CRITERIA[key] ? key : null;
    }

    // ------------------------------------------------------------------
    // Element index lookup
    // ------------------------------------------------------------------

    function waitForIndex(onReady) {
        var startedAt = Date.now();
        function poll() {
            try {
                var index = Craft.elementIndex;
                if (index && index.$visibleSources) {
                    onReady(index);
                    return;
                }
                if (Date.now() - startedAt < MAX_WAIT_MS) {
                    setTimeout(poll, POLL_INTERVAL_MS);
                }
            } catch (err) {
                safeWarn('index poll failed', err);
            }
        }
        poll();
    }

    function findVisibleSource(index, sourceKey) {
        if (!sourceKey || typeof index.getSourceByKey !== 'function') {
            return null;
        }
        var $source = index.getSourceByKey(sourceKey);
        if (!$source || !$source.length) {
            return null;
        }
        if (index.$visibleSources && index.$visibleSources.index($source) === -1) {
            return null;
        }
        return $source;
    }

    /**
     * Resolve the source we should select and the filter we should apply.
     * Priority:
     *   1. Requested source is visible → use it as-is.
     *   2. Requested source is a hidden `lens:*` and `lens:all` is visible →
     *      fall back to `lens:all` and synthesize the filter from the key.
     *   3. Otherwise: no source change; any explicit filter still applies
     *      against whatever source is currently active.
     */
    function resolveTarget(index, state) {
        var $source = findVisibleSource(index, state.requestedSource);
        if ($source) {
            return {$source: $source, state: state};
        }

        var isSpecificLensSource = state.requestedSource
            && state.requestedSource.indexOf(LENS_PREFIX) === 0
            && state.requestedSource !== FALLBACK_SOURCE;

        if (!isSpecificLensSource) {
            return {$source: null, state: state};
        }

        var $fallback = findVisibleSource(index, FALLBACK_SOURCE);
        if (!$fallback) {
            return {$source: null, state: state};
        }

        if (!state.criteria) {
            var derived = filterKeyFromSource(state.requestedSource);
            if (derived) {
                state.criteria = FILTER_CRITERIA[derived];
                state.chipPrefix = Craft.t('lens', 'Lens filter:');
                state.chipLabel = FILTER_LABELS[derived] || derived;
            }
        }

        return {$source: $fallback, state: state};
    }

    // ------------------------------------------------------------------
    // Filter injection via getViewParams monkey-patch
    // ------------------------------------------------------------------

    function mergeCriteriaInto(viewParams, criteria) {
        viewParams.criteria = viewParams.criteria || {};
        for (var key in criteria) {
            if (Object.prototype.hasOwnProperty.call(criteria, key)) {
                viewParams.criteria[key] = criteria[key];
            }
        }
    }

    function clearFilterState(index) {
        index._lensFilterCriteria = null;
        index._lensFilterSourceKey = null;
        removeChip(index);
        stripLensFilterParams();
    }

    function patchGetViewParams(index) {
        if (index._lensFilterPatched) {
            return;
        }
        index._lensFilterPatched = true;

        var original = index.getViewParams;

        index.getViewParams = function() {
            try {
                var viewParams = original.apply(this, arguments);
                var onPinnedSource = this._lensFilterCriteria
                    && this.sourceKey === this._lensFilterSourceKey;

                if (onPinnedSource) {
                    mergeCriteriaInto(viewParams, this._lensFilterCriteria);
                } else if (this._lensFilterCriteria) {
                    clearFilterState(this);
                }
                return viewParams;
            } catch (err) {
                safeWarn('filter patch failed, falling back to native params', err);
                return original.apply(this, arguments);
            }
        };
    }

    function applyFilter(index, criteria, pinnedSourceKey) {
        if (!criteria) {
            return;
        }
        patchGetViewParams(index);
        index._lensFilterCriteria = criteria;
        index._lensFilterSourceKey = pinnedSourceKey;
    }

    // ------------------------------------------------------------------
    // Filter chip (dismissible "Lens filter: X" badge)
    // ------------------------------------------------------------------

    function removeChip(index) {
        if (index && index.$container && index.$container.length) {
            index.$container.find(CHIP_SELECTOR).remove();
        }
    }

    function buildChip(prefix, label, onClear) {
        var $row = window.$('<div/>', {
            'data-lens-target': 'filter-chip',
            'class': 'lens-filter-chip-row',
        });

        var $prefix = window.$('<span/>', {
            'class': 'lens-filter-chip-row__label',
            text: prefix || Craft.t('lens', 'Lens filter:'),
        });

        var $chip = window.$('<div/>', {'class': 'lens-filter-chip'});
        var $label = window.$('<span/>', {
            'class': 'lens-filter-chip__label',
            text: label,
        });
        var $close = window.$('<button/>', {
            type: 'button',
            'class': 'lens-filter-chip__close',
            'aria-label': Craft.t('lens', 'Clear Lens filter'),
            title: Craft.t('lens', 'Clear Lens filter'),
            text: '×',
        });

        $close.on('click', function() {
            try {
                $row.remove();
                onClear();
            } catch (err) {
                safeWarn('clear filter failed', err);
            }
        });

        $chip.append($label).append($close);
        $row.append($prefix).append($chip);
        return $row;
    }

    function mountChip(index, $chip) {
        var $toolbar = index.$container.find('#toolbar').first();
        if ($toolbar.length) {
            $chip.insertAfter($toolbar);
        } else {
            index.$container.prepend($chip);
        }
    }

    function stripLensFilterParams() {
        try {
            if (typeof window.history === 'undefined' || typeof window.history.replaceState !== 'function') {
                return;
            }
            var url = new URL(window.location.href);
            url.searchParams.delete('lensFilter');
            url.searchParams.delete('lensTag');
            url.searchParams.delete('lensColor');
            window.history.replaceState({}, '', url.toString());
        } catch (err) {
            safeWarn('URL cleanup failed', err);
        }
    }

    function renderChip(index, chipPrefix, chipLabel) {
        try {
            if (!chipLabel || !index.$container || !index.$container.length) {
                return;
            }
            if (index.$container.find(CHIP_SELECTOR).length) {
                return;
            }

            var $chip = buildChip(chipPrefix, chipLabel, function() {
                index._lensFilterCriteria = null;
                index._lensFilterSourceKey = null;
                stripLensFilterParams();
                if (typeof index.updateElements === 'function') {
                    index.updateElements();
                }
            });
            mountChip(index, $chip);
        } catch (err) {
            safeWarn('filter chip render failed', err);
        }
    }

    // ------------------------------------------------------------------
    // Search box pre-fill
    // ------------------------------------------------------------------

    function applySearch(index, searchText) {
        if (!searchText || !index.$search || !index.$search.length) {
            return;
        }
        if (index.$search.val() === searchText) {
            return;
        }
        index.$search.val(searchText);
        if (typeof index.stopSearching === 'function' && typeof index.startSearching === 'function') {
            index.startSearching();
        }
    }

    // ------------------------------------------------------------------
    // Orchestrator
    // ------------------------------------------------------------------

    function bootstrap() {
        if (!window.Craft) {
            return;
        }

        var params = readParams();
        if (!isLensRequest(params)) {
            return;
        }

        var resolved = resolveCriteria(params);
        var state = {
            requestedSource: params.source,
            search: params.search,
            criteria: resolved.criteria,
            chipPrefix: resolved.chipPrefix,
            chipLabel: resolved.chipLabel,
        };

        waitForIndex(function(index) {
            try {
                var target = resolveTarget(index, state);
                var pinnedKey = target.$source ? target.$source.data('key') : index.sourceKey;

                applyFilter(index, target.state.criteria, pinnedKey);

                if (target.$source && index.sourceKey !== target.$source.data('key')
                    && typeof index.selectSource === 'function') {
                    index.selectSource(target.$source);
                } else if (target.state.criteria && typeof index.updateElements === 'function') {
                    index.updateElements();
                }

                renderChip(index, target.state.chipPrefix, target.state.chipLabel);
                applySearch(index, target.state.search);
            } catch (err) {
                safeWarn('bootstrap step failed, user keeps the native view', err);
            }
        });
    }

    try {
        bootstrap();
    } catch (err) {
        safeWarn('bootstrap aborted, user keeps the native view', err);
    }
})();
