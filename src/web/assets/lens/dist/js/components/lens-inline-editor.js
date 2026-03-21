/**
 * Lens Plugin - Inline Editor Component
 * Field editing component with people detection and detection toggle support
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Inline Editor Component
     * Handles inline editing for text fields, people detection, and detection toggles
     */
    const LensInlineEditor = {
        _initialized: false,

        /**
         * Initialize inline editor
         */
        init: function() {
            if (this._initialized) return;
            if (!this._shouldInit()) return;
            this._bindEvents();
            this._initialized = true;
        },

        /**
         * Check if inline editor should initialize
         * @returns {boolean}
         * @private
         */
        _shouldInit: function() {
            return document.querySelector('[data-lens-target="editable-field"]') !== null ||
                   document.querySelector('[data-lens-target="people-detection"]') !== null ||
                   document.querySelector('[data-lens-target="detection-toggle"]') !== null ||
                   document.querySelector('[data-lens-edit-mode="toggle"]') !== null;
        },

        /**
         * Bind all event handlers using DOM delegation
         * @private
         */
        _bindEvents: function() {
            var DOM = window.Lens.core.DOM;

            // Standard field editing
            DOM.delegate('[data-lens-action="field-edit"]', 'click', this._handleFieldEdit.bind(this));
            DOM.delegate('[data-lens-action="field-save"]', 'click', this._handleFieldSave.bind(this));
            DOM.delegate('[data-lens-action="field-cancel"]', 'click', this._handleFieldCancel.bind(this));
            DOM.delegate('[data-lens-action="field-revert"]', 'click', this._handleFieldRevert.bind(this));

            // People detection editing
            DOM.delegate('[data-lens-action="people-edit"]', 'click', this._handlePeopleEdit.bind(this));
            DOM.delegate('[data-lens-action="people-cancel"]', 'click', this._handlePeopleCancel.bind(this));
            DOM.delegate('[data-lens-action="people-revert"]', 'click', this._handlePeopleRevert.bind(this));

            // People detection auto-save on radio change
            DOM.delegate('[data-lens-control="people-mode"]', 'change', this._handlePeopleAutoSave.bind(this));

            // Detection toggle editing
            DOM.delegate('[data-lens-action="detection-edit"]', 'click', this._handleDetectionEdit.bind(this));
            DOM.delegate('[data-lens-action="detection-cancel"]', 'click', this._handleDetectionCancel.bind(this));
            DOM.delegate('[data-lens-action="detection-revert"]', 'click', this._handleDetectionRevert.bind(this));

            // Detection toggle auto-save on segmented control change
            DOM.delegate('[data-lens-control="detection-radio"]', 'change', this._handleDetectionAutoSave.bind(this));

            // Taxonomy edit mode toggle
            DOM.delegate('[data-lens-action="taxonomy-edit"]', 'click', this._handleTaxonomyEdit.bind(this));
            DOM.delegate('[data-lens-action="taxonomy-done"]', 'click', this._handleTaxonomyDone.bind(this));
        },

        // ================================================================
        // Standard Field Editing
        // ================================================================

        _handleFieldEdit: function(e, trigger) {
            var fieldEl = window.Lens.core.DOM.findTarget(trigger, 'editable-field');
            if (fieldEl) {
                window.Lens.core.DOM.enterEditMode(fieldEl, 'field-display', 'field-edit');
            }
        },

        _handleFieldCancel: function(e, trigger) {
            var fieldEl = window.Lens.core.DOM.findTarget(trigger, 'editable-field');
            if (!fieldEl) return;

            // Restore original value from display
            var displayText = fieldEl.querySelector('[data-lens-target="field-display"] p');
            var input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');
            if (displayText && input) {
                input.value = displayText.textContent;
            }

            window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'field-edit');
        },

        _handleFieldSave: function(e, saveBtn) {
            var fieldEl = window.Lens.core.DOM.findTarget(saveBtn, 'editable-field');
            if (!fieldEl) return;

            var analysisId = fieldEl.dataset.lensAnalysisId;
            var fieldName = fieldEl.dataset.lensField;
            var siteId = fieldEl.dataset.lensSiteId || null;
            var input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');
            if (!input) return;

            var value = input.value;

            window.Lens.core.ButtonState.withLoading(saveBtn, Craft.t('lens', 'Saving...'), () => {
                return window.Lens.core.API.updateField(analysisId, fieldName, value, siteId ? { siteId: siteId } : undefined)
                    .then((response) => {
                        if (response.data.success) {
                            this._updateFieldDisplay(fieldEl, response.data);
                            this._showLockIcon(fieldEl);
                            this._updateAISuggestion(fieldEl, response.data);
                            window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'field-edit');
                            window.Lens.core.DOM.resetFormBaseline(fieldEl);
                            Craft.cp.displayNotice(Craft.t('lens', 'Field updated.'));
                        }
                    });
            });
        },

        _handleFieldRevert: function(e, revertBtn) {
            var fieldEl = window.Lens.core.DOM.findTarget(revertBtn, 'editable-field');
            if (!fieldEl) return;

            var analysisId = fieldEl.dataset.lensAnalysisId;
            var fieldName = fieldEl.dataset.lensField;
            var siteId = fieldEl.dataset.lensSiteId || null;

            window.Lens.core.API.revertField(analysisId, fieldName, siteId ? { siteId: siteId } : undefined).then((response) => {
                if (response.data.success) {
                    // Update display and input
                    var revertedValue = response.data.value || '';
                    var displayP = fieldEl.querySelector('[data-lens-target="field-display"] p');
                    var input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');

                    this._setDisplayText(displayP, revertedValue);
                    if (input) input.value = revertedValue;

                    // Remove lock icon and AI suggestion (values now match AI)
                    this._removeLockIcon(fieldEl);
                    this._removeAISuggestion(fieldEl);
                    window.Lens.core.DOM.resetFormBaseline(fieldEl);

                    Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI value.'));
                }
            }).catch(() => {
                Craft.cp.displayError(Craft.t('lens', 'Failed to revert.'));
            });
        },

        // ================================================================
        // People Detection Editing (auto-save on radio change)
        // ================================================================

        _handlePeopleEdit: function(e, trigger) {
            var fieldEl = window.Lens.core.DOM.findTarget(trigger, 'people-detection');
            if (!fieldEl) return;

            var containsPeople = fieldEl.dataset.lensContainsPeople === '1';
            var faceCount = parseInt(fieldEl.dataset.lensFaceCount, 10) || 0;

            // Use service to get the mode
            var mode = window.Lens.services.PeopleDetection.fieldsToMode(containsPeople, faceCount);

            // Select appropriate radio
            var edit = fieldEl.querySelector('[data-lens-target="people-edit-panel"]');
            if (edit) {
                var radios = edit.querySelectorAll('[data-lens-control="people-mode"]');
                radios.forEach((radio) => {
                    radio.checked = (radio.value === mode);
                });

                window.Lens.core.DOM.enterEditMode(fieldEl, 'field-display', 'people-edit-panel');
            }
        },

        _handlePeopleCancel: function(e, trigger) {
            var fieldEl = window.Lens.core.DOM.findTarget(trigger, 'people-detection');
            if (fieldEl) {
                window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'people-edit-panel');
            }
        },

        /**
         * Auto-save people detection when radio selection changes
         */
        _handlePeopleAutoSave: function(e, radio) {
            var fieldEl = window.Lens.core.DOM.findTarget(radio, 'people-detection');
            if (!fieldEl) return;

            var analysisId = fieldEl.dataset.lensAnalysisId;

            // Use service to map mode to fields
            var fields = window.Lens.services.PeopleDetection.modeToFields(radio.value);
            if (!fields) {
                Craft.cp.displayError(Craft.t('lens', 'Invalid selection'));
                return;
            }

            // Review context: skip AJAX, update form hidden inputs + UI only
            if (this._isReviewContext(fieldEl)) {
                this._syncHiddenInput('containsPeople', fields.containsPeople ? '1' : '0');
                this._syncHiddenInput('faceCount', fields.faceCount.toString());
                this._updatePeopleDisplay(fieldEl, fields);
                this._showLockIcon(fieldEl);
                this._showPeopleAISuggestion(fieldEl, fields);
                this._updatePeopleBadge(fieldEl, fields);
                return;
            }

            this._withSavingState(fieldEl, this._savePeopleFields(analysisId, fields), (responses) => {
                this._updatePeopleDisplay(fieldEl, fields);
                this._showLockIcon(fieldEl);
                this._showPeopleAISuggestion(fieldEl, fields);
                this._updatePeopleBadge(fieldEl, fields);
                window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'people-edit-panel');
                this._dispatchPeopleUpdateEvent(analysisId, fields);
                window.Lens.core.DOM.resetFormBaseline(fieldEl);
                Craft.cp.displayNotice(Craft.t('lens', 'People detection updated.'));
            });
        },

        _handlePeopleRevert: function(e, revertBtn) {
            var fieldEl = window.Lens.core.DOM.findTarget(revertBtn, 'people-detection');
            var analysisId = revertBtn.dataset.lensAnalysisId;

            // Review context: client-side revert (no AJAX)
            if (this._isReviewContext(fieldEl)) {
                var containsPeopleAi = fieldEl.dataset.lensContainsPeopleAi === '1';
                var faceCountAi = parseInt(fieldEl.dataset.lensFaceCountAi) || 0;
                var aiFields = { containsPeople: containsPeopleAi, faceCount: faceCountAi };
                var aiMode = window.Lens.services.PeopleDetection.fieldsToMode(containsPeopleAi, faceCountAi);

                var radios = fieldEl.querySelectorAll('[data-lens-control="people-mode"]');
                radios.forEach(function(radio) { radio.checked = (radio.value === aiMode); });
                this._syncHiddenInput('containsPeople', containsPeopleAi ? '1' : '0');
                this._syncHiddenInput('faceCount', faceCountAi.toString());
                this._revertPeopleUI(fieldEl, aiFields);
                return;
            }

            var promises = [
                window.Lens.core.API.revertField(analysisId, 'containsPeople'),
                window.Lens.core.API.revertField(analysisId, 'faceCount')
            ];

            Promise.all(promises).then((responses) => {
                // Build fields from AI values (now restored)
                var containsPeopleResp = responses[0];
                var faceCountResp = responses[1];
                var fields = {
                    containsPeople: !!parseInt(containsPeopleResp.data.value),
                    faceCount: parseInt(faceCountResp.data.value) || 0
                };

                if (fieldEl) {
                    this._revertPeopleUI(fieldEl, fields);
                    this._dispatchPeopleUpdateEvent(analysisId, fields);
                    window.Lens.core.DOM.resetFormBaseline(fieldEl);
                }

                Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI values.'));
            }).catch(() => {
                Craft.cp.displayError(Craft.t('lens', 'Failed to revert.'));
            });
        },

        // ================================================================
        // Detection Toggle Editing (auto-save on segmented control change)
        // ================================================================

        _handleDetectionEdit: function(e, trigger) {
            var fieldEl = window.Lens.core.DOM.findTarget(trigger, 'detection-toggle');
            if (!fieldEl) return;

            var editPanel = fieldEl.querySelector('[data-lens-target="detection-edit-panel"]');
            if (editPanel) window.Lens.core.DOM.show(editPanel);
        },

        _handleDetectionCancel: function(e, trigger) {
            var fieldEl = window.Lens.core.DOM.findTarget(trigger, 'detection-toggle');
            if (!fieldEl) return;

            var isDetected = fieldEl.dataset.lensDetected === '1';
            var currentValue = this._restoreDetectionRadios(fieldEl, isDetected);

            var hiddenInput = fieldEl.querySelector('[data-lens-target="detection-value"]');
            if (hiddenInput) hiddenInput.value = currentValue;

            var editPanel = fieldEl.querySelector('[data-lens-target="detection-edit-panel"]');
            if (editPanel) window.Lens.core.DOM.hide(editPanel);
        },

        /**
         * Auto-save detection toggle when segmented control changes
         */
        _handleDetectionAutoSave: function(e, radio) {
            var fieldEl = window.Lens.core.DOM.findTarget(radio, 'detection-toggle');
            if (!fieldEl) return;

            var analysisId = fieldEl.dataset.lensAnalysisId;
            var fieldName = fieldEl.dataset.lensField;
            var value = radio.value;

            // Update hidden input
            var hiddenInput = fieldEl.querySelector('[data-lens-target="detection-value"]');
            if (hiddenInput) hiddenInput.value = value;

            var trueValue = fieldEl.dataset.lensTrueValue;
            var isNowDetected = (value === trueValue);

            // Review context: skip AJAX, update form hidden inputs + UI only
            if (this._isReviewContext(fieldEl)) {
                this._syncHiddenInput(fieldName, value);
                this._updateDetectionUI(fieldEl, isNowDetected);
                this._showLockIcon(fieldEl);
                this._showDetectionAISuggestion(fieldEl, isNowDetected);
                return;
            }

            this._withSavingState(fieldEl, window.Lens.core.API.updateField(analysisId, fieldName, value), (response) => {
                if (response.data.success) {
                    this._updateDetectionUI(fieldEl, isNowDetected);
                    this._showLockIcon(fieldEl);
                    this._showDetectionAISuggestion(fieldEl, isNowDetected);

                    var editPanel = fieldEl.querySelector('[data-lens-target="detection-edit-panel"]');
                    if (editPanel) window.Lens.core.DOM.hide(editPanel);

                    window.Lens.core.DOM.resetFormBaseline(fieldEl);
                    Craft.cp.displayNotice(Craft.t('lens', 'Detection updated.'));
                }
            });
        },

        _handleDetectionRevert: function(e, revertBtn) {
            var fieldEl = window.Lens.core.DOM.findTarget(revertBtn, 'detection-toggle');
            var analysisId = revertBtn.dataset.lensAnalysisId;
            var fieldName = revertBtn.dataset.lensField;
            var isDetectedAi = fieldEl.dataset.lensDetectedAi === '1';

            // Review context: client-side revert (no AJAX)
            if (this._isReviewContext(fieldEl)) {
                var revertedValue = this._applyDetectionRevert(fieldEl, isDetectedAi);
                this._syncHiddenInput(fieldName, revertedValue);
                return;
            }

            window.Lens.core.API.revertField(analysisId, fieldName).then((response) => {
                if (response.data.success) {
                    if (fieldEl) {
                        this._applyDetectionRevert(fieldEl, isDetectedAi);
                        window.Lens.core.DOM.resetFormBaseline(fieldEl);
                    }
                    Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI value.'));
                }
            }).catch(() => {
                Craft.cp.displayError(Craft.t('lens', 'Failed to revert.'));
            });
        },

        /**
         * Shared UI update for detection revert (both review and non-review paths).
         * @returns {string} The reverted radio value
         */
        _applyDetectionRevert: function(fieldEl, isDetectedAi) {
            var revertedValue = this._restoreDetectionRadios(fieldEl, isDetectedAi);
            this._updateDetectionUI(fieldEl, isDetectedAi);

            var hiddenInput = fieldEl.querySelector('[data-lens-target="detection-value"]');
            if (hiddenInput) hiddenInput.value = revertedValue;

            this._removeLockIcon(fieldEl);
            var suggestion = fieldEl.querySelector('[data-lens-target="detection-ai-suggestion"]');
            if (suggestion) window.Lens.core.DOM.hide(suggestion);

            return revertedValue;
        },

        // ================================================================
        // Taxonomy Edit Mode Toggle
        // ================================================================

        _handleTaxonomyEdit: function(e, trigger) {
            var container = trigger.closest('[data-lens-edit-mode="toggle"]');
            if (!container) return;
            container.classList.add('lens-is-editing');
            var tagInput = container.querySelector('[data-lens-control="tag-input"]');
            if (tagInput) tagInput.focus();
        },

        _handleTaxonomyDone: function(e, trigger) {
            var container = trigger.closest('[data-lens-edit-mode="toggle"]');
            if (!container) return;
            container.classList.remove('lens-is-editing');
        },

        // ================================================================
        // Helper Methods
        // ================================================================

        _setDisplayText: function(displayP, value) {
            if (!displayP) return;
            if (value) {
                displayP.textContent = value;
                displayP.classList.remove('light');
            } else {
                displayP.textContent = Craft.t('lens', 'Not available');
                displayP.classList.add('light');
            }
        },

        _updateFieldDisplay: function(fieldEl, data) {
            var displayP = fieldEl.querySelector('[data-lens-target="field-display"] p');
            this._setDisplayText(displayP, data.value);

            var header = fieldEl.querySelector('[data-lens-target="field-header"]');
            var badge = header ? header.querySelector('[data-lens-target="confidence-badge"]') : null;
            if (badge) {
                var badgeTooltip = badge.closest('craft-tooltip');
                (badgeTooltip || badge).remove();
            }
        },

        /**
         * Common UI cleanup after people detection revert (both review and normal paths).
         */
        _revertPeopleUI: function(fieldEl, fields) {
            this._updatePeopleDisplay(fieldEl, fields);
            this._updatePeopleBadge(fieldEl, fields);
            this._removeLockIcon(fieldEl);
            var suggestion = fieldEl.querySelector('[data-lens-target="people-ai-suggestion"]');
            if (suggestion) window.Lens.core.DOM.hide(suggestion);
        },

        _savePeopleFields: function(analysisId, fields) {
            var promises = [
                window.Lens.core.API.updateField(analysisId, 'containsPeople', fields.containsPeople),
                window.Lens.core.API.updateField(analysisId, 'faceCount', fields.faceCount)
            ];
            return Promise.all(promises);
        },

        _updatePeopleDisplay: function(fieldEl, fields) {
            var DOM = window.Lens.core.DOM;
            fieldEl.dataset.lensContainsPeople = fields.containsPeople ? '1' : '0';
            fieldEl.dataset.lensFaceCount = fields.faceCount;

            // people-present/absent only exist on asset edit page (not review)
            var present = fieldEl.querySelector('[data-lens-target="people-present"]');
            var absent = fieldEl.querySelector('[data-lens-target="people-absent"]');
            if (!present && !absent) return;

            if (fields.containsPeople) {
                DOM.show(present);
                DOM.hide(absent);
                var helpText = present.querySelector('[data-lens-target="people-help-text"]');
                if (helpText) {
                    helpText.textContent = fields.faceCount > 0
                        ? Craft.t('lens', 'May require model releases for commercial use')
                        : Craft.t('lens', 'People visible but faces obscured. May still require releases for commercial use.');
                }
            } else {
                DOM.hide(present);
                DOM.show(absent);
            }
        },

        /**
         * Update the people detection badge after auto-save.
         * SVG icon may become stale (user vs check) until page reload — acceptable trade-off.
         */
        _updatePeopleBadge: function(fieldEl, fields) {
            var badge = fieldEl.querySelector('[data-lens-target="people-badge"]');
            if (!badge) return;

            var displayText = badge.querySelector('[data-lens-target="people-display-text"]');

            badge.className = 'lens-detection-badge ' + (fields.containsPeople ? 'lens-detection-badge--info' : 'lens-detection-badge--clear');
            if (displayText) {
                displayText.textContent = fields.containsPeople
                    ? window.Lens.services.PeopleDetection.formatText(fields.containsPeople, fields.faceCount)
                    : Craft.t('lens', 'Clear');
            }
        },

        /**
         * Update the detection toggle badge after auto-save.
         * Preserves the inline SVG icon rendered by Craft's iconSvg().
         */
        _updateDetectionBadge: function(fieldEl, isDetected) {
            var badge = fieldEl.querySelector('[data-lens-target="detection-badge"]');
            if (!badge) return;

            // Extract the existing SVG so we can re-insert it after updating
            var svg = badge.querySelector('svg');

            if (isDetected) {
                badge.className = 'lens-detection-badge lens-detection-badge--flagged';
                badge.textContent = ' ' + Craft.t('lens', 'Flagged');
            } else {
                badge.className = 'lens-detection-badge lens-detection-badge--clear';
                badge.textContent = ' ' + Craft.t('lens', 'Clear');
            }

            // Re-insert SVG icon before text
            if (svg) {
                badge.insertBefore(svg, badge.firstChild);
            }
        },

        /**
         * Wrap an AJAX save promise with saving-state class and error handling.
         */
        _withSavingState: function(fieldEl, promise, onSuccess) {
            fieldEl.classList.add('lens-is-saving');
            promise
                .then(onSuccess)
                .catch(function() {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to save.'));
                })
                .finally(function() {
                    fieldEl.classList.remove('lens-is-saving');
                });
        },

        /**
         * Toggle an AI suggestion container's visibility based on whether
         * the current value differs from the AI value. Shows the revert
         * button only when values differ.
         */
        _toggleAISuggestion: function(suggestion, aiDiffers, revertAction) {
            if (!suggestion) return;
            var DOM = window.Lens.core.DOM;
            if (aiDiffers) {
                var revertBtn = suggestion.querySelector('[data-lens-action="' + revertAction + '"]');
                if (revertBtn) DOM.show(revertBtn);
                DOM.show(suggestion);
            } else {
                DOM.hide(suggestion);
            }
        },

        _showDetectionAISuggestion: function(fieldEl, isNowDetected) {
            var isDetectedAi = fieldEl.dataset.lensDetectedAi === '1';
            var aiDiffers = (isDetectedAi !== isNowDetected);
            var suggestion = fieldEl.querySelector('[data-lens-target="detection-ai-suggestion"]');
            this._toggleAISuggestion(suggestion, aiDiffers, 'detection-revert');
        },

        /**
         * Update all detection UI elements after a state change:
         * data attribute, badge, row accent, and icon class.
         */
        _updateDetectionUI: function(fieldEl, isDetected) {
            fieldEl.dataset.lensDetected = isDetected ? '1' : '0';
            this._updateDetectionBadge(fieldEl, isDetected);
            this._updateDetectionRowAccent(fieldEl, isDetected);
            var icon = fieldEl.querySelector('[data-lens-target="detection-icon"]');
            if (icon) icon.classList.toggle('lens-detection-icon--flagged', isDetected);
        },

        /**
         * Restore detection radios to match a given state and return the resolved value string.
         */
        _restoreDetectionRadios: function(fieldEl, isDetected) {
            var trueValue = fieldEl.dataset.lensTrueValue;
            var falseValue = fieldEl.dataset.lensFalseValue;
            var value = isDetected ? trueValue : falseValue;
            var radios = fieldEl.querySelectorAll('[data-lens-control="detection-radio"]');
            radios.forEach(function(radio) { radio.checked = (radio.value === value); });
            return value;
        },

        /**
         * Update detection toggle row accent (flagged vs clear)
         */
        _updateDetectionRowAccent: function(fieldEl, isDetected) {
            fieldEl.classList.toggle('lens-accent-bar', isDetected);
            fieldEl.classList.toggle('lens-accent-bar--red', isDetected);

            var detailEl = fieldEl.querySelector('[data-lens-target="detection-detail"]');
            if (detailEl) {
                detailEl.dataset.lensChipsActive = isDetected ? '1' : '0';
            }
        },

        _showLockIcon: function(fieldEl) {
            var header = fieldEl.querySelector('[data-lens-target="field-header"]');
            if (!header) return;

            // Hide AI badge icon (wand) + its craft-tooltip wrapper — lock replaces it visually
            var aiBadge = header.querySelector('[data-lens-target="ai-badge-icon"]');
            if (aiBadge) {
                var aiBadgeTooltip = aiBadge.closest('craft-tooltip');
                window.Lens.core.DOM.hide(aiBadgeTooltip || aiBadge);
            }

            var confidenceBadge = header.querySelector('[data-lens-target="confidence-badge"]');
            if (confidenceBadge) {
                var badgeTooltip = confidenceBadge.closest('craft-tooltip');
                (badgeTooltip || confidenceBadge).remove();
            }

            if (!header.querySelector('[data-lens-target="lock-icon"]')) {
                var tpl = document.querySelector('[data-lens-target="lock-template"]');
                if (!tpl) return;
                var tooltip = tpl.content.cloneNode(true);

                // Insert after detection icon (if present), otherwise before first child
                var detectionIcon = header.querySelector('[data-lens-target="detection-icon"]');
                if (detectionIcon && detectionIcon.nextSibling) {
                    header.insertBefore(tooltip, detectionIcon.nextSibling);
                } else {
                    header.insertBefore(tooltip, header.firstChild);
                }
            }
        },

        _removeLockIcon: function(fieldEl) {
            var lock = fieldEl.querySelector('[data-lens-target="lock-icon"]');
            if (lock) {
                // Remove the craft-tooltip wrapper along with the lock
                var lockTooltip = lock.closest('craft-tooltip');
                (lockTooltip || lock).remove();
            }

            // Restore AI badge icon (wand) + its craft-tooltip wrapper
            var aiBadge = fieldEl.querySelector('[data-lens-target="ai-badge-icon"]');
            if (aiBadge) {
                var aiBadgeTooltip = aiBadge.closest('craft-tooltip');
                window.Lens.core.DOM.show(aiBadgeTooltip || aiBadge);
            }
        },

        _updateAISuggestion: function(fieldEl, data) {
            var nullAiIsValid = fieldEl.dataset.lensNullAiValid === '1';
            var aiSuggestion = fieldEl.querySelector('[data-lens-target="field-ai-suggestion"]');
            if (!aiSuggestion) return;

            var aiDiffers = data.aiValue ? (data.aiValue !== data.value) : (nullAiIsValid && data.value);
            if (aiDiffers) {
                var textSpan = aiSuggestion.querySelector('[data-lens-target="ai-suggestion-text"]');
                if (textSpan) {
                    if (data.aiValue) {
                        var maxLen = window.Lens.config.THRESHOLDS.AI_SUGGESTION_PREVIEW_LENGTH;
                        var truncated = data.aiValue.length > maxLen ? data.aiValue.substring(0, maxLen) + '...' : data.aiValue;
                        textSpan.textContent = Craft.t('lens', 'AI suggested: "{value}"', { value: truncated });
                    } else {
                        textSpan.textContent = Craft.t('lens', 'AI suggested: No text detected');
                    }
                }
            }
            this._toggleAISuggestion(aiSuggestion, aiDiffers, 'field-revert');
        },

        _showPeopleAISuggestion: function(fieldEl, fields) {
            var containsPeopleAi = fieldEl.dataset.lensContainsPeopleAi === '1';
            var faceCountAi = parseInt(fieldEl.dataset.lensFaceCountAi) || 0;
            var aiDiffers = (containsPeopleAi !== fields.containsPeople) || (faceCountAi !== fields.faceCount);

            var suggestion = fieldEl.querySelector('[data-lens-target="people-ai-suggestion"]');
            if (!suggestion) return;

            if (aiDiffers) {
                var aiText = window.Lens.services.PeopleDetection.formatText(containsPeopleAi, faceCountAi);
                var textSpan = suggestion.querySelector('[data-lens-target="people-ai-text"]');
                if (textSpan) {
                    textSpan.textContent = Craft.t('lens', 'AI suggested: "{text}"', { text: aiText });
                }
            }
            this._toggleAISuggestion(suggestion, aiDiffers, 'people-revert');
        },

        _removeAISuggestion: function(fieldEl) {
            var suggestion = fieldEl.querySelector('[data-lens-target="field-ai-suggestion"]') ||
                             fieldEl.querySelector('[data-lens-target="people-ai-suggestion"]');
            if (suggestion) {
                window.Lens.core.DOM.hide(suggestion);
            }
        },

        _isReviewContext: function(fieldEl) {
            return fieldEl && fieldEl.dataset.lensContext === 'review';
        },

        _syncHiddenInput: function(fieldName, value) {
            var input = window.Lens.core.DOM.findControl('field-' + fieldName);
            if (input) input.value = String(value);
        },

        _dispatchPeopleUpdateEvent: function(analysisId, fields) {
            document.dispatchEvent(new CustomEvent('lens:peopleDetectionUpdated', {
                detail: {
                    analysisId: analysisId,
                    containsPeople: fields.containsPeople,
                    faceCount: fields.faceCount
                }
            }));
        }
    };

    window.Lens.components.InlineEditor = LensInlineEditor;

    // Auto-initialize
    Lens.utils.onReady(function() {
        LensInlineEditor.init();
    });
})();
