/**
 * Lens Plugin - Review Page
 * Handles review interactions (keyboard shortcuts, form submission, people detection, detection toggles)
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var DOM = window.Lens.core.DOM;

    const LensReviewPage = {
        _initialized: false,

        init: function() {
            if (this._initialized) return;
            if (!document.querySelector('[data-lens-target="review-view"]')) return;

            this._bindKeyboardShortcuts();
            this._bindPeopleDetection();
            this._bindDetectionToggles();
            this._bindBridgeReviewInputs();
            this._bindFormSubmit();
            this._initialized = true;
        },

        // ================================================================
        // Keyboard Shortcuts: A (approve), S (skip), R (reject)
        // ================================================================

        _bindKeyboardShortcuts: function() {
            document.addEventListener('keydown', function(e) {
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
            DOM.delegate('input[data-lens-control="people-mode-review"]', 'change', function(e, radio) {
                if (!radio.checked) return;

                // Use PeopleDetectionService for mapping
                var fields = window.Lens.services.PeopleDetection.modeToFields(radio.value);
                if (!fields) return;

                // Update hidden inputs
                var containsPeopleInput = DOM.findControl('field-containsPeople');
                var faceCountInput = DOM.findControl('field-faceCount');

                if (containsPeopleInput) containsPeopleInput.value = fields.containsPeople ? '1' : '0';
                if (faceCountInput) faceCountInput.value = fields.faceCount.toString();

                var container = radio.closest('[data-lens-target="people-detection"]');
                if (!container) return;

                // Update status badge text
                var displayText = container.querySelector('[data-lens-target="people-display-text"]');
                if (displayText) {
                    displayText.textContent = Lens.utils.formatPeopleDetectionText(fields.containsPeople, fields.faceCount);
                }

                // Toggle AI suggestion visibility based on whether selection matches AI
                var aiSuggestion = container.querySelector('[data-lens-target="people-ai-suggestion"]');
                if (aiSuggestion) {
                    var aiContainsPeople = container.dataset.lensContainsPeopleAi === '1';
                    var aiFaceCount = parseInt(container.dataset.lensFaceCountAi, 10) || 0;
                    var matchesAi = (fields.containsPeople === aiContainsPeople) && (fields.faceCount === aiFaceCount);
                    aiSuggestion.hidden = matchesAi;
                }
            });
        },

        // ================================================================
        // Detection Toggles (NSFW, Watermark, Brands)
        // ================================================================

        _bindDetectionToggles: function() {
            DOM.delegate('input[data-lens-control="detection-radio-review"]', 'change', function(e, radio) {
                if (!radio.checked) return;

                var fieldName = radio.dataset.lensField;
                if (!fieldName) return;

                // Update the corresponding hidden input
                var hiddenInput = DOM.findControl('field-' + fieldName);
                if (hiddenInput) {
                    hiddenInput.value = radio.value;
                }

                var container = radio.closest('[data-lens-target="detection-toggle"]');
                if (!container) return;

                var currentDetected = parseFloat(radio.value) > 0;

                // Update accent bar and icon styling
                container.classList.toggle('lens-accent-bar', currentDetected);
                container.classList.toggle('lens-accent-bar--red', currentDetected);
                var icon = container.querySelector('[data-lens-target="detection-icon"]');
                if (icon) icon.classList.toggle('lens-detection-icon--flagged', currentDetected);

                // Update status badge
                var badge = container.querySelector('[data-lens-target="detection-badge"]');
                if (badge) {
                    badge.className = 'lens-detection-badge ' + (currentDetected ? 'lens-detection-badge--flagged' : 'lens-detection-badge--clear');
                    var srcIcon = container.querySelector('[data-lens-target="' + (currentDetected ? 'detection-icon-flagged' : 'detection-icon-clear') + '"]');
                    badge.innerHTML = (srcIcon ? srcIcon.innerHTML : '') + ' ' + Craft.t('lens', currentDetected ? 'Flagged' : 'Clear');
                }

                // Toggle AI suggestion visibility based on whether selection matches AI
                var aiSuggestion = container.querySelector('[data-lens-target="detection-ai-suggestion"]');
                if (aiSuggestion) {
                    var aiDetected = container.dataset.lensDetectedAi === '1';
                    aiSuggestion.hidden = (currentDetected === aiDetected);
                }
            });
        },

        // ================================================================
        // Bridge Review Inputs — Dynamic AI suggestion + client-side revert
        // ================================================================

        _bindBridgeReviewInputs: function() {
            // Show/hide AI suggestion inline as user types in bridge review inputs
            DOM.delegate('[data-lens-target="bridge-review-input"]', 'input', function(e, input) {
                var container = input.closest('[data-lens-target="bridge-field"]');
                if (!container) return;

                var aiDiv = container.querySelector('[data-lens-target="bridge-review-ai"]');
                var aiValue = input.dataset.lensAiValue;
                if (!aiDiv || !aiValue) return;

                var differs = (input.value !== aiValue);
                aiDiv.hidden = !differs;

                // Also show/hide the revert button
                var revertBtn = aiDiv.querySelector('[data-lens-action="bridge-review-revert"]');
                if (revertBtn) revertBtn.hidden = !differs;

                // Update AI text if showing
                if (differs) {
                    var textSpan = aiDiv.querySelector('[data-lens-target="bridge-review-ai-text"]');
                    if (textSpan) {
                        var maxLen = window.Lens.config.THRESHOLDS.AI_PREVIEW_LENGTH;
                        var truncated = aiValue.length > maxLen ? aiValue.substring(0, maxLen) + '...' : aiValue;
                        textSpan.textContent = Craft.t('lens', 'AI: "{value}"', { value: truncated });
                    }
                }
            });

            // Client-side revert: restore input value to original AI value
            DOM.delegate('[data-lens-action="bridge-review-revert"]', 'click', function(e, btn) {
                var container = btn.closest('[data-lens-target="bridge-field"]');
                if (!container) return;

                var input = container.querySelector('[data-lens-target="bridge-review-input"]');
                var aiValue = btn.dataset.lensAiValue;
                if (input && aiValue) {
                    input.value = aiValue;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
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
                var tagsInput = DOM.findControl('field-tags');
                if (tagEditor && tagsInput && window.Lens.services && window.Lens.services.Taxonomy) {
                    var tags = window.Lens.services.Taxonomy.collectTags(tagEditor);
                    tagsInput.value = JSON.stringify(tags);
                }

                // Serialize colors → JSON string in hidden input
                var colorEditor = document.querySelector('[data-lens-target="color-editor"]');
                var colorsInput = DOM.findControl('field-colors');
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
                        var titleInput = group.querySelector('[data-lens-control="field-suggestedTitle"]');
                        var altInput = group.querySelector('[data-lens-control="field-altText"]');

                        if (titleInput && titleInput.value) fields.suggestedTitle = titleInput.value;
                        if (altInput && altInput.value) fields.altText = altInput.value;

                        if (Object.keys(fields).length > 0) {
                            siteContent[siteId] = fields;
                        }
                    });

                    if (Object.keys(siteContent).length > 0) {
                        var siteContentInput = DOM.findControl('field-siteContent');
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

    Lens.utils.onReady(function() {
        LensReviewPage.init();
    });
})();
