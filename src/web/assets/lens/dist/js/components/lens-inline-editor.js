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
                   document.querySelector('[data-lens-target="detection-toggle"]') !== null;
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
                            this._updateEditMeta(fieldEl, response.data);
                            window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'field-edit');
                            this._resetFormBaseline(fieldEl);
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
                    var displayP = fieldEl.querySelector('[data-lens-target="field-display"] p');
                    var input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');

                    if (displayP) displayP.textContent = response.data.value;
                    if (input) input.value = response.data.value;

                    // Remove lock icon and meta
                    this._removeLockIcon(fieldEl);
                    this._removeEditMeta(fieldEl);
                    this._removeAISuggestion(fieldEl);
                    this._resetFormBaseline(fieldEl);

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

            // Show saving state
            fieldEl.classList.add('is-saving');

            this._savePeopleFields(analysisId, fields).then((responses) => {
                this._updatePeopleDisplay(fieldEl, fields);
                this._showLockIcon(fieldEl);
                this._updatePeopleEditMeta(fieldEl, responses[0].data);
                this._showPeopleAISuggestion(fieldEl, fields);
                this._updatePeopleBadge(fieldEl, fields);
                this._updatePeopleRowAccent(fieldEl, fields);
                window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'people-edit-panel');
                this._dispatchPeopleUpdateEvent(analysisId, fields);
                this._resetFormBaseline(fieldEl);
                Craft.cp.displayNotice(Craft.t('lens', 'People detection updated.'));
            }).catch(() => {
                Craft.cp.displayError(Craft.t('lens', 'Failed to save.'));
            }).finally(() => {
                fieldEl.classList.remove('is-saving');
            });
        },

        _handlePeopleRevert: function(e, revertBtn) {
            var fieldEl = window.Lens.core.DOM.findTarget(revertBtn, 'people-detection');
            var analysisId = revertBtn.dataset.lensAnalysisId;
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
                    this._updatePeopleDisplay(fieldEl, fields);
                    this._updatePeopleBadge(fieldEl, fields);
                    this._updatePeopleRowAccent(fieldEl, fields);
                    this._removeLockIcon(fieldEl);
                    this._removeEditMeta(fieldEl);

                    // Hide AI suggestion (values now match AI)
                    var suggestion = fieldEl.querySelector('[data-lens-target="people-ai-suggestion"]');
                    if (suggestion) window.Lens.core.DOM.hide(suggestion);

                    // Update data attributes to AI values
                    fieldEl.dataset.lensContainsPeople = fields.containsPeople ? '1' : '0';
                    fieldEl.dataset.lensFaceCount = fields.faceCount;

                    this._dispatchPeopleUpdateEvent(analysisId, fields);
                    this._resetFormBaseline(fieldEl);
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

            // Restore radio to current state
            var isDetected = fieldEl.dataset.lensDetected === '1';
            var trueValue = fieldEl.dataset.lensTrueValue;
            var falseValue = fieldEl.dataset.lensFalseValue;
            var currentValue = isDetected ? trueValue : falseValue;

            var radios = fieldEl.querySelectorAll('[data-lens-control="detection-radio"]');
            radios.forEach(function(radio) {
                radio.checked = (radio.value === currentValue);
            });

            // Restore hidden input
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

            // Show saving state
            fieldEl.classList.add('is-saving');

            window.Lens.core.API.updateField(analysisId, fieldName, value)
                .then((response) => {
                    if (response.data.success) {
                        // Update data attribute
                        var trueValue = fieldEl.dataset.lensTrueValue;
                        var isNowDetected = (value === trueValue);
                        fieldEl.dataset.lensDetected = isNowDetected ? '1' : '0';

                        // Update badge
                        this._updateDetectionBadge(fieldEl, isNowDetected);

                        // Update row accent
                        this._updateDetectionRowAccent(fieldEl, isNowDetected);

                        // Update icon color
                        var icon = fieldEl.querySelector('[data-lens-target="detection-icon"]');
                        if (icon) {
                            icon.classList.toggle('lens-detection-icon--flagged', isNowDetected);
                        }

                        this._showLockIcon(fieldEl);

                        // Show AI suggestion (always after editing)
                        this._showDetectionAISuggestion(fieldEl, isNowDetected);

                        // Show edit meta
                        var meta = fieldEl.querySelector('[data-lens-target="detection-edit-meta"]');
                        if (meta) {
                            if (response.data.editedBy) {
                                meta.innerHTML = Lens.utils.formatEditMeta(response.data);
                            } else {
                                meta.textContent = Craft.t('lens', 'Manually edited');
                            }
                            window.Lens.core.DOM.show(meta);
                        }

                        // Close edit panel
                        var editPanel = fieldEl.querySelector('[data-lens-target="detection-edit-panel"]');
                        if (editPanel) window.Lens.core.DOM.hide(editPanel);

                        this._resetFormBaseline(fieldEl);
                        Craft.cp.displayNotice(Craft.t('lens', 'Detection updated.'));
                    }
                })
                .catch(() => {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to save.'));
                })
                .finally(() => {
                    fieldEl.classList.remove('is-saving');
                });
        },

        _handleDetectionRevert: function(e, revertBtn) {
            var fieldEl = window.Lens.core.DOM.findTarget(revertBtn, 'detection-toggle');
            var analysisId = revertBtn.dataset.lensAnalysisId;
            var fieldName = revertBtn.dataset.lensField;

            window.Lens.core.API.revertField(analysisId, fieldName).then((response) => {
                if (response.data.success) {
                    if (fieldEl) {
                        // Determine reverted detection state from AI value
                        var isDetectedAi = fieldEl.dataset.lensDetectedAi === '1';
                        fieldEl.dataset.lensDetected = isDetectedAi ? '1' : '0';

                        // Update badge, row accent, icon
                        this._updateDetectionBadge(fieldEl, isDetectedAi);
                        this._updateDetectionRowAccent(fieldEl, isDetectedAi);
                        var icon = fieldEl.querySelector('[data-lens-target="detection-icon"]');
                        if (icon) {
                            icon.classList.toggle('lens-detection-icon--flagged', isDetectedAi);
                        }

                        // Update segmented control radios to match
                        var trueValue = fieldEl.dataset.lensTrueValue;
                        var falseValue = fieldEl.dataset.lensFalseValue;
                        var revertedValue = isDetectedAi ? trueValue : falseValue;
                        var radios = fieldEl.querySelectorAll('[data-lens-control="detection-radio"]');
                        radios.forEach(function(radio) {
                            radio.checked = (radio.value === revertedValue);
                        });
                        var hiddenInput = fieldEl.querySelector('[data-lens-target="detection-value"]');
                        if (hiddenInput) hiddenInput.value = revertedValue;

                        // Remove lock, meta, AI suggestion (values now match AI)
                        this._removeLockIcon(fieldEl);
                        var meta = fieldEl.querySelector('[data-lens-target="detection-edit-meta"]');
                        if (meta) {
                            meta.textContent = '';
                            window.Lens.core.DOM.hide(meta);
                        }
                        var suggestion = fieldEl.querySelector('[data-lens-target="detection-ai-suggestion"]');
                        if (suggestion) window.Lens.core.DOM.hide(suggestion);

                        this._resetFormBaseline(fieldEl);
                    }

                    Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI value.'));
                }
            }).catch(() => {
                Craft.cp.displayError(Craft.t('lens', 'Failed to revert.'));
            });
        },

        // ================================================================
        // Helper Methods
        // ================================================================

        _updateFieldDisplay: function(fieldEl, data) {
            var displayP = fieldEl.querySelector('[data-lens-target="field-display"] p');
            if (displayP) {
                displayP.textContent = data.value;
            }

            // Remove confidence badge (field is now user-edited)
            var header = fieldEl.querySelector('[data-lens-target="field-header"]');
            var badge = header ? header.querySelector('[data-lens-target="confidence-badge"]') : null;
            if (badge) badge.remove();
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

            var present = fieldEl.querySelector('[data-lens-target="people-present"]');
            var absent = fieldEl.querySelector('[data-lens-target="people-absent"]');

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

            badge.className = 'lens-detection-badge lens-detection-badge--clear';
            if (displayText) {
                displayText.textContent = fields.containsPeople
                    ? window.Lens.services.PeopleDetection.formatText(fields.containsPeople, fields.faceCount)
                    : Craft.t('lens', 'Clear');
            }
        },

        /**
         * Update people detection row accent (no-op, people detection has no accent)
         */
        _updatePeopleRowAccent: function() {},

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
         * Show detection toggle AI suggestion after auto-save.
         * Always shown for provenance; revert button only when AI differs.
         * The AI suggestion text is pre-rendered by Twig — we only toggle visibility.
         */
        _showDetectionAISuggestion: function(fieldEl, isNowDetected) {
            var DOM = window.Lens.core.DOM;
            var isDetectedAi = fieldEl.dataset.lensDetectedAi === '1';
            var aiDiffers = (isDetectedAi !== isNowDetected);

            var suggestion = fieldEl.querySelector('[data-lens-target="detection-ai-suggestion"]');
            if (!suggestion) return;

            // Only show when AI value differs from current
            if (aiDiffers) {
                DOM.show(suggestion);
                var revertBtn = suggestion.querySelector('[data-lens-action="detection-revert"]');
                if (revertBtn) DOM.show(revertBtn);
            } else {
                DOM.hide(suggestion);
            }
        },

        /**
         * Update detection toggle row accent (flagged vs clear)
         */
        _updateDetectionRowAccent: function(fieldEl, isDetected) {
            fieldEl.classList.toggle('lens-accent-bar', isDetected);
            fieldEl.classList.toggle('lens-accent-bar--red', isDetected);
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

            // Remove confidence badge (field is now user-edited)
            var confidenceBadge = header.querySelector('[data-lens-target="confidence-badge"]');
            if (confidenceBadge) confidenceBadge.remove();

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

        _updateEditMeta: function(fieldEl, data) {
            var DOM = window.Lens.core.DOM;

            var meta = fieldEl.querySelector('[data-lens-target="field-edit-meta"]');
            if (meta) {
                if (data.editedBy) {
                    meta.innerHTML = Lens.utils.formatEditMeta(data);
                    DOM.show(meta);
                } else {
                    meta.innerHTML = '';
                    DOM.hide(meta);
                }
            }

            var aiSuggestion = fieldEl.querySelector('[data-lens-target="field-ai-suggestion"]');
            if (aiSuggestion) {
                if (data.aiValue && data.aiValue !== data.value) {
                    var maxLen = window.Lens.config.THRESHOLDS.AI_SUGGESTION_PREVIEW_LENGTH;
                    var truncated = data.aiValue.length > maxLen ? data.aiValue.substring(0, maxLen) + '...' : data.aiValue;
                    var textSpan = aiSuggestion.querySelector('[data-lens-target="ai-suggestion-text"]');
                    if (textSpan) {
                        textSpan.textContent = Craft.t('lens', 'AI suggested: "{value}"', { value: truncated });
                    }
                    var revertBtn = aiSuggestion.querySelector('[data-lens-action="field-revert"]');
                    if (revertBtn) DOM.show(revertBtn);
                    DOM.show(aiSuggestion);
                } else {
                    DOM.hide(aiSuggestion);
                }
            }
        },

        _updatePeopleEditMeta: function(fieldEl, data) {
            var meta = fieldEl.querySelector('[data-lens-target="people-edit-meta"]');
            if (meta) {
                if (data && data.editedBy) {
                    meta.innerHTML = Lens.utils.formatEditMeta(data);
                } else {
                    meta.textContent = Craft.t('lens', 'Manually edited');
                }
                window.Lens.core.DOM.show(meta);
            }
        },

        _showPeopleAISuggestion: function(fieldEl, fields) {
            var DOM = window.Lens.core.DOM;
            var containsPeopleAi = fieldEl.dataset.lensContainsPeopleAi === '1';
            var faceCountAi = parseInt(fieldEl.dataset.lensFaceCountAi) || 0;
            var aiDiffers = (containsPeopleAi !== fields.containsPeople) || (faceCountAi !== fields.faceCount);

            var suggestion = fieldEl.querySelector('[data-lens-target="people-ai-suggestion"]');
            if (!suggestion) return;

            // Only show when AI value differs from current
            if (aiDiffers) {
                var aiText = window.Lens.services.PeopleDetection.formatText(containsPeopleAi, faceCountAi);
                var textSpan = suggestion.querySelector('[data-lens-target="people-ai-text"]');
                if (textSpan) {
                    textSpan.textContent = Craft.t('lens', 'AI suggested: "{text}"', { text: aiText });
                }
                var revertBtn = suggestion.querySelector('[data-lens-action="people-revert"]');
                if (revertBtn) DOM.show(revertBtn);
                DOM.show(suggestion);
            } else {
                DOM.hide(suggestion);
            }
        },

        _removeEditMeta: function(fieldEl) {
            var meta = fieldEl.querySelector('[data-lens-target="field-edit-meta"]') ||
                       fieldEl.querySelector('[data-lens-target="people-edit-meta"]');
            if (meta) {
                meta.innerHTML = '';
                window.Lens.core.DOM.hide(meta);
            }
        },

        _removeAISuggestion: function(fieldEl) {
            var suggestion = fieldEl.querySelector('[data-lens-target="field-ai-suggestion"]') ||
                             fieldEl.querySelector('[data-lens-target="people-ai-suggestion"]');
            if (suggestion) {
                window.Lens.core.DOM.hide(suggestion);
            }
        },

        /**
         * Reset Craft's form change tracker after AJAX save.
         * Prevents "Changes you made may not be saved" warning on page unload.
         */
        _resetFormBaseline: function(el) {
            var $form = $(el).closest('form[data-confirm-unload]');
            if ($form.length) {
                var serializer = $form.data('serializer');
                $form.data('initialSerializedValue', typeof serializer === 'function' ? serializer() : $form.serialize());
            }
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
