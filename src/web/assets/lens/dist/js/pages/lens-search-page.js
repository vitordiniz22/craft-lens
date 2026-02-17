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
            this.initClearSearch();
            this.initConfidenceInputs();
            this.initSearchEnterKey();
            this.initPresetButtonGroups();
            this.initColorToleranceSlider();
            this.initDatePickers();
            this.initKeyboardNavigation();
            this._initialized = true;
        },

        initFilterToggle: function () {
            window.Lens.core.DOM.delegate(
                '[data-lens-action="toggle-filters"]',
                'click',
                (e, btn) => {
                    const panel = window.Lens.core.DOM.findTarget(
                        btn,
                        'filters-panel',
                    );
                    if (panel) window.Lens.core.DOM.toggleClass(panel);
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

        initConfidenceInputs: function () {
            const form = document.querySelector(
                '[data-lens-target="search-form"]',
            );
            if (!form) return;

            form.addEventListener('submit', () => {
                const confidenceMin =
                    window.Lens.core.DOM.findControl('confidence-min');
                const confidenceMax =
                    window.Lens.core.DOM.findControl('confidence-max');
                let selects = form.querySelectorAll('select');
                let hiddenInputs = form.querySelectorAll(
                    'input[type="hidden"]',
                );
                let inputs = form.querySelectorAll(
                    'input[type="text"], input[type="number"], input[type="date"]',
                );

                [confidenceMin, confidenceMax].forEach((input) => {
                    if (input && input.value) {
                        const val = parseFloat(input.value);
                        if (val > 1) input.value = (val / 100).toFixed(2);
                    }
                });

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
            const groups = document.querySelectorAll('[data-lens-preset]');
            groups.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const groupName = btn.dataset.lensPreset;
                    const value = btn.dataset.lensValue;
                    const isActive =
                        btn.getAttribute('aria-pressed') === 'true';

                    // Toggle this button
                    btn.setAttribute('aria-pressed', !isActive);
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
                        const siblings = document.querySelectorAll(
                            '[data-lens-preset="' + groupName + '"]',
                        );
                        siblings.forEach((sibling) => {
                            if (sibling !== btn) {
                                sibling.setAttribute('aria-pressed', 'false');
                                sibling.classList.remove('active');
                            }
                        });
                    }
                });
            });
        },

        initColorToleranceSlider: function () {
            const slider = window.Lens.core.DOM.findControl('color-tolerance');
            if (!slider) return;

            slider.addEventListener('input', () => {
                const display = document.querySelector(
                    '[data-lens-target="tolerance-value"]',
                );
                if (display) display.textContent = slider.value;
            });
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
                    var link = focused.querySelector('.lens-result-preview');
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
            var currentIndex = -1;
            if (focused) {
                for (var i = 0; i < results.length; i++) {
                    if (results[i] === focused) {
                        currentIndex = i;
                        break;
                    }
                }
            }

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
