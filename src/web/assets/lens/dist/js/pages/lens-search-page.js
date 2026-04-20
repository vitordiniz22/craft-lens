/**
 * Lens Plugin — Search Page
 *
 * Filter UI lives in `components/lens-filter-picker.js`. This module handles
 * search input, chip × removal, result-card keyboard navigation, view selector,
 * and the cleanup pass that strips empty form fields before submit.
 */
(function () {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var DOM = window.Lens.core.DOM;

    var LensSearchPage = {
        _initialized: false,

        init: function () {
            if (this._initialized) return;
            if (!document.querySelector('[data-lens-target="search-form"]'))
                return;

            this.initFilterChips();
            this.initClearSearch();
            this.initFormCleanup();
            this.initSearchEnterKey();
            this.initViewSelector();
            this.initKeyboardNavigation();
            this._initialized = true;
        },

        initViewSelector: function () {
            DOM.delegate('[data-lens-action="set-view"]', 'click', function (e, btn) {
                var layout = btn.dataset.lensValue;
                var isMini = layout === 'mini';

                document.querySelectorAll('[data-lens-target="results-grid"]').forEach(function (grid) {
                    grid.classList.toggle('is-mini', isMini);
                });

                var group = btn.closest('[data-lens-target="view-selector"]');
                if (group) {
                    group.querySelectorAll('[data-lens-action="set-view"]').forEach(function (sibling) {
                        var active = sibling === btn;
                        sibling.classList.toggle('active', active);
                        sibling.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                }

                Craft.sendActionRequest('POST', 'lens/user-settings/set-asset-browser-layout', {
                    data: { layout: layout }
                }).catch(function () {
                    Craft.cp.displayError(Craft.t('lens', 'Could not save view preference.'));
                });
            });
        },

        initFilterChips: function () {
            DOM.delegate(
                '[data-lens-action="remove-filter-chip"]',
                'click',
                function (e, btn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var url = new URL(window.location.href);
                    btn.dataset.lensFilterParam.split(',').forEach(function (param) {
                        Lens.utils.stripUrlParam(url, param.trim());
                    });
                    window.location.assign(url.toString());
                }
            );
        },

        initClearSearch: function () {
            DOM.delegate(
                '[data-lens-action="clear-search"]',
                'click',
                function () {
                    var input = document.querySelector(
                        '[data-lens-control="search-query"]'
                    );
                    if (input) {
                        input.value = '';
                        var form = input.closest('form');
                        if (form) form.submit();
                    }
                }
            );
        },

        initFormCleanup: function () {
            var form = document.querySelector(
                '[data-lens-target="search-form"]'
            );
            if (!form) return;

            form.addEventListener('submit', function () {
                var fields = form.querySelectorAll(
                    'input[type="text"], input[type="number"], input[type="date"], input[type="hidden"], select'
                );

                fields.forEach(function (field) {
                    if (field.tagName === 'SELECT') {
                        if (field.multiple) {
                            var hasSelection = Array.from(field.options).some(function (opt) { return opt.selected; });
                            if (!hasSelection) field.disabled = true;
                        } else if (!field.value) {
                            field.disabled = true;
                        }
                    } else if (!field.value) {
                        field.disabled = true;
                    }
                });

                setTimeout(function () {
                    fields.forEach(function (field) { field.disabled = false; });
                }, window.Lens.config.ANIMATION.FORM_FIELD_DEBOUNCE_MS);
            });
        },

        initSearchEnterKey: function () {
            DOM.delegate(
                '[data-lens-control="search-query"]',
                'keydown',
                function (e, input) {
                    if (e.key === 'Enter') {
                        var form = input.closest('form');
                        if (form) form.submit();
                    }
                }
            );
        },

        initKeyboardNavigation: function () {
            document.addEventListener('keydown', this.handleKeydown.bind(this));
        },

        handleKeydown: function (e) {
            if (e.key === '/' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                var searchInput = DOM.findControl('search-query');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }

            if (e.key === 'Escape') {
                var escInput = DOM.findControl('search-query');
                if (escInput && document.activeElement === escInput) {
                    escInput.value = '';
                    escInput.blur();
                }
            }

            if (
                (e.key === 'ArrowUp' || e.key === 'ArrowDown') &&
                !e.target.matches('input, textarea, select')
            ) {
                e.preventDefault();
                this.navigateResults(e.key === 'ArrowDown' ? 1 : -1);
            }

            if (
                e.key === 'Enter' &&
                !e.target.matches('input, textarea, button, a')
            ) {
                var focused = document.querySelector(
                    '[data-lens-target="result-card"].lens-asset-card--focused'
                );
                if (focused) {
                    var link = focused.querySelector('[data-lens-target="result-link"]');
                    if (link) link.click();
                }
            }
        },

        navigateResults: function (direction) {
            var results = document.querySelectorAll(
                '[data-lens-target="result-card"]'
            );
            if (results.length === 0) return;

            var focused = document.querySelector(
                '[data-lens-target="result-card"].lens-asset-card--focused'
            );
            var currentIndex = focused ? Array.from(results).indexOf(focused) : -1;

            var newIndex = currentIndex + direction;

            if (newIndex >= 0 && newIndex < results.length) {
                if (focused) focused.classList.remove('lens-asset-card--focused');
                results[newIndex].classList.add('lens-asset-card--focused');
                results[newIndex].focus();
            }
        }
    };

    window.Lens.pages.SearchPage = LensSearchPage;

    Lens.utils.onReady(function () {
        LensSearchPage.init();
    });
})();
