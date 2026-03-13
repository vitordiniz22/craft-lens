/**
 * Lens Plugin - Search Page
 * Handles search filtering, keyboard navigation, and duplicate resolution
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
            var panel = document.querySelector(
                '[data-lens-target="filters-panel"]'
            );

            DOM.delegate(
                '[data-lens-action="toggle-filters"]',
                'click',
                function () {
                    if (panel) DOM.toggleClass(panel);
                }
            );
        },

        initFilterChips: function () {
            DOM.delegate(
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
                var selects = form.querySelectorAll('select');
                var hiddenInputs = form.querySelectorAll(
                    'input[type="hidden"]'
                );
                var inputs = form.querySelectorAll(
                    'input[type="text"], input[type="number"], input[type="date"]'
                );

                inputs.forEach(function (input) {
                    if (!input.value) input.disabled = true;
                });

                hiddenInputs.forEach(function (input) {
                    if (!input.value) input.disabled = true;
                });

                selects.forEach(function (select) {
                    if (select.multiple) {
                        var hasSelection = Array.from(select.options).some(
                            function (opt) {
                                return opt.selected;
                            }
                        );

                        if (!hasSelection) select.disabled = true;
                    } else if (!select.value) {
                        select.disabled = true;
                    }
                });

                setTimeout(function () {
                    inputs.forEach(function (input) { input.disabled = false; });
                    hiddenInputs.forEach(function (input) { input.disabled = false; });
                    selects.forEach(function (select) { select.disabled = false; });
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

        initPresetButtonGroups: function () {
            DOM.delegate('[data-lens-value]', 'click', function (e, btn) {
                var group = btn.closest('[data-lens-preset]');
                if (!group) return;

                var groupName = group.dataset.lensPreset;
                var value = btn.dataset.lensValue;
                var isActive = btn.getAttribute('aria-pressed') === 'true';

                // Toggle this button
                btn.setAttribute('aria-pressed', isActive ? 'false' : 'true');
                btn.classList.toggle('active');

                // Update hidden input
                var hiddenInput = document.querySelector(
                    'input[name="' + groupName + '"]'
                );
                if (hiddenInput) {
                    hiddenInput.value = isActive ? '' : value;
                }

                // Deactivate other buttons in same group
                if (!isActive) {
                    var siblings =
                        group.querySelectorAll('[data-lens-value]');
                    siblings.forEach(function (sibling) {
                        if (sibling !== btn) {
                            sibling.setAttribute('aria-pressed', 'false');
                            sibling.classList.remove('active');
                        }
                    });
                }
            });
        },

        initColorInput: function () {
            // Craft CMS native selector (forms.color() macro — no data-lens-* available)
            var colorInput = document.querySelector('input[name="color"]');
            if (!colorInput) return;

            var toleranceWrap = document.querySelector(
                '[data-lens-target="color-tolerance-wrap"]'
            );
            if (!toleranceWrap) return;

            var updateVisibility = function () {
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
            // Craft CMS native selectors (no data-lens-* available)
            var container = colorInput.closest('.color-container');
            if (container) {
                var preview = container.querySelector('.color-preview');
                if (preview) {
                    new MutationObserver(updateVisibility).observe(preview, {
                        attributes: true,
                        attributeFilter: ['style'],
                    });
                }
            }
        },

        initColorToleranceSlider: function () {
            var self = this;
            DOM.delegate(
                '[data-lens-control="color-tolerance"]',
                'input',
                function (e, slider) {
                    var display = document.querySelector(
                        '[data-lens-target="tolerance-value"]'
                    );
                    if (display) display.textContent = slider.value;
                    var label = document.querySelector(
                        '[data-lens-target="tolerance-label"]'
                    );
                    if (label)
                        label.textContent =
                            '(' +
                            self.getToleranceLabel(parseInt(slider.value)) +
                            ')';
                }
            );
        },

        getToleranceLabel: function (value) {
            if (value <= 20) return Craft.t('lens', 'Exact');
            if (value <= 50) return Craft.t('lens', 'Similar');
            if (value <= 75) return Craft.t('lens', 'Broad');
            return Craft.t('lens', 'Very broad');
        },

        initDatePickers: function () {
            var dateFrom = DOM.findControl('date-from');
            var dateTo = DOM.findControl('date-to');

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
                var searchInput = DOM.findControl('search-query');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }

            // Escape to clear search
            if (e.key === 'Escape') {
                var escInput = DOM.findControl('search-query');
                if (escInput && document.activeElement === escInput) {
                    escInput.value = '';
                    escInput.blur();
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
                    '[data-lens-target="result-card"][data-lens-focused]'
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
                '[data-lens-target="result-card"][data-lens-focused]'
            );
            var currentIndex = focused ? Array.from(results).indexOf(focused) : -1;

            var newIndex = currentIndex + direction;

            if (newIndex >= 0 && newIndex < results.length) {
                if (focused) delete focused.dataset.lensFocused;
                results[newIndex].dataset.lensFocused = '';
                results[newIndex].focus();
            }
        }
    };

    window.Lens.pages.SearchPage = LensSearchPage;

    Lens.utils.onReady(function () {
        LensSearchPage.init();
    });
})();
