/**
 * Lens Plugin - Bulk Review Page
 * Handles bulk selection, filtering, and form submission for batch review actions
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var DOM = window.Lens.core.DOM;

    var LensBulkReviewPage = {
        _initialized: false,
        _form: null,

        init: function() {
            if (this._initialized) return;

            this._form = document.querySelector('[data-lens-target="bulk-review-form"]');
            if (!this._form) return;

            this._bindCheckboxes();
            this._bindSelectionActions();
            this._bindFormSubmit();
            this._initialized = true;
        },

        // ================================================================
        // Checkbox change → update selection count and button states
        // ================================================================

        _bindCheckboxes: function() {
            var self = this;
            DOM.delegate('[data-lens-control="bulk-checkbox"]', 'change', function() {
                self._updateSelectionCount();
            });
        },

        _updateSelectionCount: function() {
            var count = this._form.querySelectorAll('[data-lens-control="bulk-checkbox"]:checked').length;
            var countDisplay = this._form.querySelector('[data-lens-target="bulk-selection-count"]');
            var approveBtn = this._form.querySelector('[data-lens-action="bulk-approve"]');
            var rejectBtn = this._form.querySelector('[data-lens-action="bulk-reject"]');

            if (countDisplay) {
                countDisplay.textContent = Craft.t('lens', '{count} selected', {count: count});
            }
            if (approveBtn) approveBtn.disabled = count === 0;
            if (rejectBtn) rejectBtn.disabled = count === 0;
        },

        // ================================================================
        // Select All / Deselect All / Select High Confidence
        // ================================================================

        _bindSelectionActions: function() {
            var self = this;

            DOM.delegate('[data-lens-action="bulk-select-all"]', 'click', function() {
                self._setAllCheckboxes(true);
            });

            DOM.delegate('[data-lens-action="bulk-deselect-all"]', 'click', function() {
                self._setAllCheckboxes(false);
            });

            DOM.delegate('[data-lens-action="bulk-select-high"]', 'click', function() {
                self._selectHighConfidence();
            });
        },

        _setAllCheckboxes: function(checked) {
            var checkboxes = this._form.querySelectorAll('[data-lens-control="bulk-checkbox"]');
            checkboxes.forEach(function(cb) {
                cb.checked = checked;
            });
            this._updateSelectionCount();
        },

        _selectHighConfidence: function() {
            var checkboxes = this._form.querySelectorAll('[data-lens-control="bulk-checkbox"]');
            checkboxes.forEach(function(cb) {
                var item = cb.closest('[data-lens-confidence]');
                var confidence = item ? parseFloat(item.dataset.lensConfidence) : 0;
                cb.checked = confidence >= 0.8;
            });
            this._updateSelectionCount();
        },

        // ================================================================
        // Form Submit — confirm and switch action for reject
        // ================================================================

        _bindFormSubmit: function() {
            this._form.addEventListener('submit', function(e) {
                var count = e.target.querySelectorAll('[data-lens-control="bulk-checkbox"]:checked').length;
                var action = e.submitter ? e.submitter.textContent.trim() : '';

                if (!confirm(Craft.t('lens', 'Are you sure you want to {action} {count} analyses?', {
                    action: action.toLowerCase(),
                    count: count
                }))) {
                    e.preventDefault();
                    return;
                }

                // Switch action input if submitter specifies a different form action
                if (e.submitter && e.submitter.dataset.lensFormAction) {
                    e.target.querySelector('input[name="action"]').value = e.submitter.dataset.lensFormAction;
                }
            });
        }
    };

    window.Lens.pages.BulkReviewPage = LensBulkReviewPage;

    Lens.utils.onReady(function() {
        LensBulkReviewPage.init();
    });
})();
