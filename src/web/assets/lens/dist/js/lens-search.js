/**
 * Lens Plugin - Search & Duplicates
 * Search page UI with filters, keyboard navigation, and duplicate resolution
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    // ==========================================================================
    // Search Page
    // ==========================================================================

    var LensSearch = {
        form: null,
        currentFocusIndex: -1,
        results: [],

        init: function() {
            var form = document.querySelector('[data-lens-target="search-form"]');
            if (!form) return;

            this.form = form;
            this.initFilterToggle();
            this.initConfidenceInputs(form);
            this.initSearchEnterKey(form);
            this.initPresetButtonGroups(form);
            this.initColorToleranceSlider();
            this.initDatePickers();
            this.initKeyboardNavigation();
        },

        // =====================================================================
        // Filters
        // =====================================================================

        initFilterToggle: function() {
            var toggleBtn = document.querySelector('[data-lens-action="toggle-filters"]');
            var filtersPanel = document.querySelector('[data-lens-target="filters-panel"]');

            if (toggleBtn && filtersPanel) {
                toggleBtn.addEventListener('click', function() {
                    filtersPanel.classList.toggle('hidden');
                });
            }
        },

        initConfidenceInputs: function(form) {
            form.addEventListener('submit', function() {
                var confidenceMin = form.querySelector('[name="confidenceMin"]');
                var confidenceMax = form.querySelector('[name="confidenceMax"]');

                if (confidenceMin && confidenceMin.value) {
                    var val = parseFloat(confidenceMin.value);
                    if (val > 1) {
                        confidenceMin.value = (val / 100).toFixed(2);
                    }
                }

                if (confidenceMax && confidenceMax.value) {
                    var val = parseFloat(confidenceMax.value);
                    if (val > 1) {
                        confidenceMax.value = (val / 100).toFixed(2);
                    }
                }

                // Clean empty fields to keep URLs clean
                var inputs = form.querySelectorAll('input, select');
                inputs.forEach(function(input) {
                    if (input.name && !input.value && input.type !== 'radio' && input.type !== 'hidden') {
                        input.disabled = true;
                    }
                });

                // Re-enable for next interaction
                setTimeout(function() {
                    inputs.forEach(function(input) {
                        input.disabled = false;
                    });
                }, 100);
            });
        },

        initSearchEnterKey: function(form) {
            var searchInput = document.querySelector('[data-lens-control="search-query"]');
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        form.submit();
                    }
                });
            }
        },

        initPresetButtonGroups: function(form) {
            document.querySelectorAll('[data-lens-preset]').forEach(function(group) {
                var presetName = group.dataset.lensPreset;
                var hiddenInput = form.querySelector('input[name="' + presetName + '"]');

                group.querySelectorAll('.btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var isActive = this.classList.contains('active');

                        group.querySelectorAll('.btn').forEach(function(b) {
                            b.classList.remove('active');
                            b.setAttribute('aria-pressed', 'false');
                        });

                        if (!isActive) {
                            this.classList.add('active');
                            this.setAttribute('aria-pressed', 'true');
                            if (hiddenInput) {
                                hiddenInput.value = this.dataset.lensValue;
                            }
                        } else {
                            if (hiddenInput) {
                                hiddenInput.value = '';
                            }
                        }
                    });
                });
            });
        },

        initColorToleranceSlider: function() {
            var toleranceSlider = document.querySelector('[data-lens-control="color-tolerance"]');
            var toleranceValue = document.querySelector('[data-lens-target="tolerance-value"]');
            if (toleranceSlider && toleranceValue) {
                toleranceSlider.addEventListener('input', function() {
                    toleranceValue.textContent = this.value;
                });
            }
        },

        initDatePickers: function() {
            var dateFromInput = document.querySelector('[data-lens-control="date-from"]');
            var dateToInput = document.querySelector('[data-lens-control="date-to"]');

            if (typeof Craft !== 'undefined' && Craft.datepickerOptions) {
                if (dateFromInput) {
                    $(dateFromInput).datepicker(Craft.datepickerOptions);
                }
                if (dateToInput) {
                    $(dateToInput).datepicker(Craft.datepickerOptions);
                }
            }
        },

        // =====================================================================
        // Keyboard Navigation
        // =====================================================================

        initKeyboardNavigation: function() {
            this.results = Array.from(document.querySelectorAll('[data-lens-target="result-card"]'));

            this.results.forEach(function(card, i) {
                card.setAttribute('tabindex', '0');
                card.setAttribute('data-result-index', i);
            });

            document.addEventListener('keydown', this.handleKeydown.bind(this));
        },

        handleKeydown: function(e) {
            var self = this;
            var isInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName);

            // "/" - Focus search (when not in input)
            if (e.key === '/' && !isInput) {
                e.preventDefault();
                var searchInput = document.querySelector('[data-lens-control="search-query"]');
                if (searchInput) searchInput.focus();
                return;
            }

            // Escape - Clear/blur
            if (e.key === 'Escape') {
                var searchInput = document.querySelector('[data-lens-control="search-query"]');
                if (document.activeElement === searchInput) {
                    searchInput.value = '';
                    searchInput.blur();
                }
                return;
            }

            // Arrow keys for result navigation (when not in input)
            if (!isInput && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                self.navigateResults(e.key === 'ArrowDown' ? 1 : -1);
                return;
            }

            // Enter on focused result
            if (e.key === 'Enter' && document.activeElement && document.activeElement.classList.contains('lens-asset-card')) {
                e.preventDefault();
                var link = document.activeElement.querySelector('.lens-result-preview');
                if (link) link.click();
            }
        },

        navigateResults: function(direction) {
            if (!this.results.length) return;

            this.currentFocusIndex = Math.max(0, Math.min(
                this.results.length - 1,
                this.currentFocusIndex + direction
            ));

            if (this.results[this.currentFocusIndex]) {
                this.results[this.currentFocusIndex].focus();
            }
        }
    };

    // ==========================================================================
    // Duplicates (shown within search results)
    // ==========================================================================

    var LensDuplicates = {
        init: function() {
            document.querySelectorAll('[data-lens-action="resolve-duplicate"]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var groupId = this.dataset.lensGroupId;
                    var resolution = this.dataset.lensResolution;
                    var card = this.closest('.lens-duplicate-card');

                    btn.disabled = true;

                    Craft.sendActionRequest('POST', 'lens/search/resolve-duplicate', {
                        data: { groupId: groupId, resolution: resolution }
                    }).then(function(response) {
                        if (response.data.success) {
                            card.style.opacity = '0.5';
                            card.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
                            Craft.cp.displayNotice(Craft.t('lens', 'Duplicate pair resolved.'));
                        }
                    }).catch(function() {
                        Craft.cp.displayError(Craft.t('lens', 'Failed to resolve.'));
                        btn.disabled = false;
                    });
                });
            });
        }
    };

    window.Lens.Search = LensSearch;
    window.Lens.Duplicates = LensDuplicates;

    // ==========================================================================
    // Initialize when DOM is ready
    // ==========================================================================

    function init() {
        LensSearch.init();
        LensDuplicates.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
