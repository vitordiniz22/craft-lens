/**
 * Lens Plugin - Search Page
 * Handles search filtering, keyboard navigation, and duplicate resolution
 */
(function () {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    const LensSearchPage = {
        _initialized: false,

        init: function () {
            if (this._initialized) return;
            if (!document.querySelector('[data-lens-target="search-form"]'))
                return;

            this.initFilterToggle();
            this.initFilterChips();
            this.initClearSearch();
            this.initFormCleanup();
            this.initSearchEnterKey();
            this.initPresetButtonGroups();
            this.initColorInput();
            this.initColorToleranceSlider();
            this.initDatePickers();
            this.initKeyboardNavigation();
            this._initialized = true;
        },

        initFilterToggle: function () {
            const panel = document.querySelector(
                '[data-lens-target="filters-panel"]',
            );

            window.Lens.core.DOM.delegate(
                '[data-lens-action="toggle-filters"]',
                'click',
                (e, btn) => {
                    if (panel) window.Lens.core.DOM.toggleClass(panel);
                },
            );
        },

        initFilterChips: function () {
            window.Lens.core.DOM.delegate(
                '[data-lens-action="remove-filter-chip"]',
                'click',
                function (e, btn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var params = btn.dataset.lensFilterParam.split(',');
                    var url = new URL(window.location.href);
                    params.forEach(function (param) {
                        url.searchParams.delete(param.trim());
                    });
                    window.location.assign(url.toString());
                },
            );
        },

        initClearSearch: function () {
            window.Lens.core.DOM.delegate(
                '[data-lens-action="clear-search"]',
                'click',
                (e, btn) => {
                    var input = document.querySelector(
                        '[data-lens-control="search-query"]',
                    );
                    if (input) {
                        input.value = '';
                        var form = input.closest('form');
                        if (form) form.submit();
                    }
                },
            );
        },

        initFormCleanup: function () {
            const form = document.querySelector(
                '[data-lens-target="search-form"]',
            );
            if (!form) return;

            form.addEventListener('submit', () => {
                let selects = form.querySelectorAll('select');
                let hiddenInputs = form.querySelectorAll(
                    'input[type="hidden"]',
                );
                let inputs = form.querySelectorAll(
                    'input[type="text"], input[type="number"], input[type="date"]',
                );

                inputs.forEach((input) => {
                    if (!input.value) input.disabled = true;
                });

                hiddenInputs.forEach((input) => {
                    if (!input.value) input.disabled = true;
                });

                selects.forEach((select) => {
                    if (select.multiple) {
                        var hasSelection = Array.from(select.options).some(
                            function (opt) {
                                return opt.selected;
                            },
                        );

                        if (!hasSelection) select.disabled = true;
                    } else if (!select.value) {
                        select.disabled = true;
                    }
                });

                setTimeout(() => {
                    inputs.forEach((input) => (input.disabled = false));
                    hiddenInputs.forEach((input) => (input.disabled = false));
                    selects.forEach((select) => (select.disabled = false));
                }, window.Lens.config.ANIMATION.FORM_FIELD_DEBOUNCE_MS);
            });
        },

        initSearchEnterKey: function () {
            const input = window.Lens.core.DOM.findControl('search-query');

            if (!input) return;

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    const form = input.closest('form');

                    if (form) form.submit();
                }
            });
        },

        initPresetButtonGroups: function () {
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-lens-value]');
                if (!btn) return;

                const group = btn.closest('[data-lens-preset]');
                if (!group) return;

                const groupName = group.dataset.lensPreset;
                const value = btn.dataset.lensValue;
                const isActive = btn.getAttribute('aria-pressed') === 'true';

                // Toggle this button
                btn.setAttribute('aria-pressed', isActive ? 'false' : 'true');
                btn.classList.toggle('active');

                // Update hidden input
                const hiddenInput = document.querySelector(
                    'input[name="' + groupName + '"]',
                );
                if (hiddenInput) {
                    hiddenInput.value = isActive ? '' : value;
                }

                // Deactivate other buttons in same group
                if (!isActive) {
                    const siblings =
                        group.querySelectorAll('[data-lens-value]');
                    siblings.forEach((sibling) => {
                        if (sibling !== btn) {
                            sibling.setAttribute('aria-pressed', 'false');
                            sibling.classList.remove('active');
                        }
                    });
                }

            });
        },

        initColorInput: function () {
            const colorInput = document.querySelector('input[name="color"]');
            if (!colorInput) return;

            const toleranceWrap = document.querySelector(
                '[data-lens-target="color-tolerance-wrap"]',
            );
            if (!toleranceWrap) return;

            const updateVisibility = () => {
                if (colorInput.value.trim()) {
                    toleranceWrap.classList.remove('hidden');
                } else {
                    toleranceWrap.classList.add('hidden');
                }
            };

            // Typing directly in the hex input
            colorInput.addEventListener('input', updateVisibility);

            // Craft.ColorInput sets the value programmatically from the
            // native picker, which doesn't fire input/change. Instead,
            // observe the preview swatch — Craft always updates its
            // background-color when the value changes.
            const container = colorInput.closest('.color-container');
            if (container) {
                const preview = container.querySelector('.color-preview');
                if (preview) {
                    new MutationObserver(updateVisibility).observe(preview, {
                        attributes: true,
                        attributeFilter: ['style'],
                    });
                }
            }
        },

        initColorToleranceSlider: function () {
            const slider = window.Lens.core.DOM.findControl('color-tolerance');
            if (!slider) return;

            slider.addEventListener('input', () => {
                const display = document.querySelector(
                    '[data-lens-target="tolerance-value"]',
                );
                if (display) display.textContent = slider.value;
                const label = document.querySelector(
                    '[data-lens-target="tolerance-label"]',
                );
                if (label)
                    label.textContent =
                        '(' +
                        this.getToleranceLabel(parseInt(slider.value)) +
                        ')';
            });
        },

        getToleranceLabel: function (value) {
            if (value <= 20) return Craft.t('lens', 'Exact');
            if (value <= 50) return Craft.t('lens', 'Similar');
            if (value <= 75) return Craft.t('lens', 'Broad');
            return Craft.t('lens', 'Very broad');
        },

        initDatePickers: function () {
            const dateFrom = window.Lens.core.DOM.findControl('date-from');
            const dateTo = window.Lens.core.DOM.findControl('date-to');

            if (dateFrom) jQuery(dateFrom).datepicker(Craft.datepickerOptions);
            if (dateTo) jQuery(dateTo).datepicker(Craft.datepickerOptions);
        },

        initKeyboardNavigation: function () {
            document.addEventListener('keydown', this.handleKeydown.bind(this));
        },

        handleKeydown: function (e) {
            // "/" to focus search
            if (e.key === '/' && !e.target.matches('input, textarea')) {
                e.preventDefault();
                const searchInput =
                    window.Lens.core.DOM.findControl('search-query');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }

            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput =
                    window.Lens.core.DOM.findControl('search-query');
                if (searchInput && document.activeElement === searchInput) {
                    searchInput.value = '';
                    searchInput.blur();
                }
            }

            // Arrow keys for result navigation
            if (
                (e.key === 'ArrowUp' || e.key === 'ArrowDown') &&
                !e.target.matches('input, textarea, select')
            ) {
                e.preventDefault();
                this.navigateResults(e.key === 'ArrowDown' ? 1 : -1);
            }

            // Enter to open focused result
            if (
                e.key === 'Enter' &&
                !e.target.matches('input, textarea, button, a')
            ) {
                var focused = document.querySelector(
                    '[data-lens-target="result-card"].focused',
                );
                if (focused) {
                    var link = focused.querySelector('[data-lens-target="result-link"]');
                    if (link) link.click();
                }
            }
        },

        navigateResults: function (direction) {
            var results = document.querySelectorAll(
                '[data-lens-target="result-card"]',
            );
            if (results.length === 0) return;

            var focused = document.querySelector(
                '[data-lens-target="result-card"].focused',
            );
            var currentIndex = focused ? Array.from(results).indexOf(focused) : -1;

            var newIndex = currentIndex + direction;

            if (newIndex >= 0 && newIndex < results.length) {
                if (focused) focused.classList.remove('focused');
                results[newIndex].classList.add('focused');
                results[newIndex].focus();
            }
        },
    };

    window.Lens.pages.SearchPage = LensSearchPage;

    function init() {
        LensSearchPage.init();
        // Also initialize duplicates if present
        if (window.Lens.Duplicates) window.Lens.Duplicates.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
