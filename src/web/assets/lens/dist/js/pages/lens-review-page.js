/**
 * Lens Plugin - Review Page
 * Handles review interactions (keyboard shortcuts, form submission, people detection, detection toggles)
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
            this._bindDetectionToggles();
            this._bindFormSubmit();
            this._initialized = true;
        },

        // ================================================================
        // Keyboard Shortcuts: A (approve), S (skip), R (reject)
        // ================================================================

        _bindKeyboardShortcuts: function() {
            document.addEventListener('keydown', (e) => {
                // Don't trigger if user is typing in an input/textarea
                if (e.target.matches('input, textarea')) return;

                var key = e.key.toLowerCase();

                if (key === 'a') {
                    e.preventDefault();
                    var approveBtn = document.querySelector('[data-lens-action="review-approve"]');
                    if (approveBtn && !approveBtn.disabled) approveBtn.click();
                } else if (key === 's') {
                    e.preventDefault();
                    var skipBtn = document.querySelector('[data-lens-action="review-skip"]');
                    if (skipBtn && !skipBtn.disabled) skipBtn.click();
                } else if (key === 'r') {
                    e.preventDefault();
                    var rejectBtn = document.querySelector('[data-lens-action="review-reject"]');
                    if (rejectBtn && !rejectBtn.disabled) rejectBtn.click();
                }
            });
        },

        // ================================================================
        // People Detection Editor
        // ================================================================

        _bindPeopleDetection: function() {
            var peopleRadios = document.querySelectorAll('input[data-lens-control="people-mode-review"]');
            if (peopleRadios.length === 0) return;

            peopleRadios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (!radio.checked) return;

                    // Use PeopleDetectionService for mapping
                    var fields = window.Lens.services.PeopleDetection.modeToFields(radio.value);
                    if (!fields) return;

                    // Update hidden inputs
                    var containsPeopleInput = window.Lens.core.DOM.findControl('field-containsPeople');
                    var faceCountInput = window.Lens.core.DOM.findControl('field-faceCount');

                    if (containsPeopleInput) containsPeopleInput.value = fields.containsPeople ? '1' : '0';
                    if (faceCountInput) faceCountInput.value = fields.faceCount.toString();
                });
            });
        },

        // ================================================================
        // Detection Toggles (NSFW, Watermark, Brands)
        // ================================================================

        _bindDetectionToggles: function() {
            var detectionRadios = document.querySelectorAll('input[data-lens-control="detection-radio-review"]');
            if (detectionRadios.length === 0) return;

            detectionRadios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (!radio.checked) return;

                    var fieldName = radio.dataset.lensField;
                    if (!fieldName) return;

                    // Update the corresponding hidden input
                    var hiddenInput = window.Lens.core.DOM.findControl('field-' + fieldName);
                    if (hiddenInput) {
                        hiddenInput.value = radio.value;
                    }
                });
            });
        },

        // ================================================================
        // Form Submit — Serialize tags and colors before submission
        // ================================================================

        _bindFormSubmit: function() {
            var form = document.querySelector('[data-lens-target="review-form"]');
            if (!form) return;

            form.addEventListener('submit', function() {
                // Serialize tags → JSON string in hidden input
                var tagEditor = document.querySelector('[data-lens-target="tag-editor"]');
                var tagsInput = window.Lens.core.DOM.findControl('field-tags');
                if (tagEditor && tagsInput && window.Lens.services && window.Lens.services.Taxonomy) {
                    var tags = window.Lens.services.Taxonomy.collectTags(tagEditor);
                    tagsInput.value = JSON.stringify(tags);
                }

                // Serialize colors → JSON string in hidden input
                var colorEditor = document.querySelector('[data-lens-target="color-editor"]');
                var colorsInput = window.Lens.core.DOM.findControl('field-colors');
                if (colorEditor && colorsInput && window.Lens.services && window.Lens.services.Taxonomy) {
                    var colors = window.Lens.services.Taxonomy.collectColors(colorEditor);
                    colorsInput.value = JSON.stringify(colors);
                }

                // Serialize per-site content → JSON string in hidden input
                var translationGroups = document.querySelectorAll('[data-lens-site-id]');
                if (translationGroups.length > 0) {
                    var siteContent = {};

                    translationGroups.forEach(function(group) {
                        var siteId = group.dataset.lensSiteId;
                        if (!siteId) return;

                        var fields = {};
                        var titleInput = group.querySelector('input[name="suggestedTitle"], textarea[name="suggestedTitle"]');
                        var altInput = group.querySelector('input[name="altText"], textarea[name="altText"]');

                        if (titleInput && titleInput.value) fields.suggestedTitle = titleInput.value;
                        if (altInput && altInput.value) fields.altText = altInput.value;

                        if (Object.keys(fields).length > 0) {
                            siteContent[siteId] = fields;
                        }
                    });

                    if (Object.keys(siteContent).length > 0) {
                        var siteContentInput = window.Lens.core.DOM.findControl('field-siteContent');
                        if (!siteContentInput) {
                            siteContentInput = document.createElement('input');
                            siteContentInput.type = 'hidden';
                            siteContentInput.name = 'siteContent';
                            siteContentInput.dataset.lensControl = 'field-siteContent';
                            form.appendChild(siteContentInput);
                        }
                        siteContentInput.value = JSON.stringify(siteContent);
                    }
                }
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
