/**
 * Lens Plugin - Review Page
 * Handles review interactions (keyboard shortcuts, form submission, people detection)
 * Refactored from lens-review.js - focal point/zoom extracted to FocalPointEditor component
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    const LensReviewPage = {
        _initialized: false,

        init: function() {
            if (this._initialized) return;
            if (!document.querySelector('[data-lens-target="review-view"]')) return;

            this._bindKeyboardShortcuts();
            this._bindPeopleDetection();
            this._initialized = true;
        },

        // ================================================================
        // Keyboard Shortcuts: A (approve), S (skip), R (reject)
        // ================================================================

        _bindKeyboardShortcuts: function() {
            document.addEventListener('keydown', (e) => {
                // Don't trigger if user is typing in an input/textarea
                if (e.target.matches('input, textarea')) return;

                const key = e.key.toLowerCase();

                if (key === 'a') {
                    e.preventDefault();
                    const approveBtn = document.querySelector('button[type="submit"].submit');
                    if (approveBtn && !approveBtn.disabled) approveBtn.click();
                } else if (key === 's') {
                    e.preventDefault();
                    const skipBtn = document.querySelector('button[formaction*="skip"]');
                    if (skipBtn && !skipBtn.disabled) skipBtn.click();
                } else if (key === 'r') {
                    e.preventDefault();
                    const rejectBtn = document.querySelector('button[formaction*="reject"]');
                    if (rejectBtn && !rejectBtn.disabled) rejectBtn.click();
                }
            });
        },

        // ================================================================
        // People Detection Editor
        // ================================================================

        _bindPeopleDetection: function() {
            const peopleRadios = document.querySelectorAll('input[data-lens-control="people-mode-review"]');
            if (peopleRadios.length === 0) return;

            peopleRadios.forEach((radio) => {
                radio.addEventListener('change', () => {
                    if (!radio.checked) return;

                    // Use PeopleDetectionService for mapping
                    const fields = window.Lens.services.PeopleDetection.modeToFields(radio.value);
                    if (!fields) return;

                    // Update hidden inputs
                    const containsPeopleInput = window.Lens.core.DOM.findControl('field-containsPeople');
                    const faceCountInput = window.Lens.core.DOM.findControl('field-faceCount');

                    if (containsPeopleInput) containsPeopleInput.value = fields.containsPeople ? '1' : '0';
                    if (faceCountInput) faceCountInput.value = fields.faceCount.toString();
                });
            });
        }
    };

    window.Lens.pages.ReviewPage = LensReviewPage;

    function init() {
        LensReviewPage.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
