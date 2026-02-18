/**
 * Lens Plugin - Inline Editor Component
 * Field editing component with people detection support
 * Extracted and refactored from lens-asset-actions.js
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Inline Editor Component
     * Handles inline editing for title, alt text, description, and people detection
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
            return document.querySelector('[data-lens-target="editable-field"]') !== null;
        },

        /**
         * Bind all event handlers using DOM delegation
         * @private
         */
        _bindEvents: function() {
            const DOM = window.Lens.core.DOM;

            // Standard field editing
            DOM.delegate('[data-lens-action="field-edit"]', 'click', this._handleFieldEdit.bind(this));
            DOM.delegate('[data-lens-action="field-save"]', 'click', this._handleFieldSave.bind(this));
            DOM.delegate('[data-lens-action="field-cancel"]', 'click', this._handleFieldCancel.bind(this));
            DOM.delegate('[data-lens-action="field-revert"]', 'click', this._handleFieldRevert.bind(this));

            // People detection editing
            DOM.delegate('[data-lens-action="people-edit"]', 'click', this._handlePeopleEdit.bind(this));
            DOM.delegate('[data-lens-action="people-save"]', 'click', this._handlePeopleSave.bind(this));
            DOM.delegate('[data-lens-action="people-cancel"]', 'click', this._handlePeopleCancel.bind(this));
            DOM.delegate('[data-lens-action="people-revert"]', 'click', this._handlePeopleRevert.bind(this));
        },

        // ================================================================
        // Standard Field Editing
        // ================================================================

        _handleFieldEdit: function(e, trigger) {
            const fieldEl = window.Lens.core.DOM.findTarget(trigger, 'editable-field');
            if (fieldEl) {
                window.Lens.core.DOM.enterEditMode(fieldEl, 'field-display', 'field-edit');
            }
        },

        _handleFieldCancel: function(e, trigger) {
            const fieldEl = window.Lens.core.DOM.findTarget(trigger, 'editable-field');
            if (!fieldEl) return;

            // Restore original value from display
            const displayText = fieldEl.querySelector('[data-lens-target="field-display"] p');
            const input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');
            if (displayText && input) {
                input.value = displayText.textContent;
            }

            window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'field-edit');
        },

        _handleFieldSave: function(e, saveBtn) {
            const fieldEl = window.Lens.core.DOM.findTarget(saveBtn, 'editable-field');
            if (!fieldEl) return;

            const analysisId = fieldEl.dataset.lensAnalysisId;
            const fieldName = fieldEl.dataset.lensField;
            const input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');
            if (!input) return;

            const value = input.value;

            window.Lens.core.ButtonState.withLoading(saveBtn, Craft.t('lens', 'Saving...'), () => {
                return window.Lens.core.API.updateField(analysisId, fieldName, value)
                    .then((response) => {
                        if (response.data.success) {
                            this._updateFieldDisplay(fieldEl, response.data);
                            this._showLockIcon(fieldEl);
                            this._updateEditMeta(fieldEl, response.data);
                            window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'field-edit');
                            Craft.cp.displayNotice(Craft.t('lens', 'Field updated.'));
                        }
                    });
            });
        },

        _handleFieldRevert: function(e, revertBtn) {
            const fieldEl = window.Lens.core.DOM.findTarget(revertBtn, 'editable-field');
            if (!fieldEl) return;

            const analysisId = fieldEl.dataset.lensAnalysisId;
            const fieldName = fieldEl.dataset.lensField;

            window.Lens.core.API.revertField(analysisId, fieldName).then((response) => {
                if (response.data.success) {
                    // Update display and input
                    const displayP = fieldEl.querySelector('[data-lens-target="field-display"] p');
                    const input = fieldEl.querySelector('[data-lens-target="field-edit"] input, [data-lens-target="field-edit"] textarea');

                    if (displayP) displayP.textContent = response.data.value;
                    if (input) input.value = response.data.value;

                    // Remove lock icon and meta
                    this._removeLockIcon(fieldEl);
                    this._removeEditMeta(fieldEl);
                    this._removeAISuggestion(fieldEl);

                    Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI value.'));
                }
            });
        },

        // ================================================================
        // People Detection Editing
        // ================================================================

        _handlePeopleEdit: function(e, trigger) {
            const fieldEl = window.Lens.core.DOM.findTarget(trigger, 'people-detection');
            if (!fieldEl) return;

            const containsPeople = fieldEl.dataset.lensContainsPeople === '1';
            const faceCount = parseInt(fieldEl.dataset.lensFaceCount, 10) || 0;

            // Use service to get the mode
            const mode = window.Lens.services.PeopleDetection.fieldsToMode(containsPeople, faceCount);

            // Select appropriate radio
            const edit = fieldEl.querySelector('[data-lens-target="people-edit-panel"]');
            if (edit) {
                const radios = edit.querySelectorAll('[data-lens-control="people-mode"]');
                radios.forEach((radio) => {
                    radio.checked = (radio.value === mode);
                });

                window.Lens.core.DOM.enterEditMode(fieldEl, 'field-display', 'people-edit-panel');
            }
        },

        _handlePeopleCancel: function(e, trigger) {
            const fieldEl = window.Lens.core.DOM.findTarget(trigger, 'people-detection');
            if (fieldEl) {
                window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'people-edit-panel');
            }
        },

        _handlePeopleSave: function(e, saveBtn) {
            const fieldEl = window.Lens.core.DOM.findTarget(saveBtn, 'people-detection');
            if (!fieldEl) return;

            const analysisId = fieldEl.dataset.lensAnalysisId;
            const edit = fieldEl.querySelector('[data-lens-target="people-edit-panel"]');
            if (!edit) return;

            const selectedRadio = edit.querySelector('[data-lens-control="people-mode"]:checked');
            if (!selectedRadio) {
                Craft.cp.displayError(Craft.t('lens', 'Please select an option'));
                return;
            }

            // Use service to map mode to fields
            const fields = window.Lens.services.PeopleDetection.modeToFields(selectedRadio.value);
            if (!fields) {
                Craft.cp.displayError(Craft.t('lens', 'Invalid selection'));
                return;
            }

            window.Lens.core.ButtonState.withLoading(saveBtn, Craft.t('lens', 'Saving...'), () => {
                return this._savePeopleFields(analysisId, fields).then(() => {
                    this._updatePeopleDisplay(fieldEl, fields);
                    this._showLockIcon(fieldEl);
                    this._updatePeopleEditMeta(fieldEl);
                    this._showPeopleAISuggestion(fieldEl, fields);
                    window.Lens.core.DOM.exitEditMode(fieldEl, 'field-display', 'people-edit-panel');
                    this._dispatchPeopleUpdateEvent(analysisId, fields);
                    Craft.cp.displayNotice(Craft.t('lens', 'People detection updated.'));
                });
            });
        },

        _handlePeopleRevert: function(e, revertBtn) {
            const analysisId = revertBtn.dataset.lensAnalysisId;
            const promises = [
                window.Lens.core.API.revertField(analysisId, 'containsPeople'),
                window.Lens.core.API.revertField(analysisId, 'faceCount')
            ];

            Promise.all(promises).then(() => {
                Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI values. Refreshing...'));
                setTimeout(() => window.location.reload(), window.Lens.config.ANIMATION.RELOAD_DELAY_MS);
            });
        },

        // ================================================================
        // Helper Methods
        // ================================================================

        _updateFieldDisplay: function(fieldEl, data) {
            const displayP = fieldEl.querySelector('[data-lens-target="field-display"] p');
            if (displayP) {
                displayP.textContent = data.value;
            }

            // Remove confidence badge (field is now user-edited)
            const header = fieldEl.querySelector('.lens-field-header');
            const badge = header ? header.querySelector('.badge') : null;
            if (badge) badge.remove();
        },

        _savePeopleFields: function(analysisId, fields) {
            const promises = [
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
                var displayText = present.querySelector('[data-lens-target="people-display-text"]');
                if (displayText) {
                    displayText.textContent = window.Lens.services.PeopleDetection.formatText(fields.containsPeople, fields.faceCount);
                }
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

        _showLockIcon: function(fieldEl) {
            const header = fieldEl.querySelector('.lens-field-header');
            if (header && !header.querySelector('.lens-lock-icon')) {
                const lock = document.createElement('span');
                lock.className = 'lens-lock-icon';
                lock.title = Craft.t('lens', 'Protected from reprocessing');
                lock.innerHTML = '&#128274;';
                header.insertBefore(lock, header.firstChild);
            }
        },

        _removeLockIcon: function(fieldEl) {
            const lock = fieldEl.querySelector('.lens-lock-icon');
            if (lock) lock.remove();
        },

        _updateEditMeta: function(fieldEl, data) {
            var DOM = window.Lens.core.DOM;

            var meta = fieldEl.querySelector('[data-lens-target="field-edit-meta"]');
            if (meta) {
                if (data.editedBy) {
                    meta.textContent = Craft.t('lens', 'Edited by {user} on {date}', {
                        user: data.editedBy,
                        date: data.editedAt
                    });
                    DOM.show(meta);
                } else {
                    meta.textContent = '';
                    DOM.hide(meta);
                }
            }

            var aiSuggestion = fieldEl.querySelector('[data-lens-target="field-ai-suggestion"]');
            if (aiSuggestion) {
                if (data.aiValue && data.aiValue !== data.value) {
                    var truncated = data.aiValue.length > 100 ? data.aiValue.substring(0, 100) + '...' : data.aiValue;
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

        _updatePeopleEditMeta: function(fieldEl) {
            var meta = fieldEl.querySelector('[data-lens-target="people-edit-meta"]');
            if (meta) {
                meta.textContent = Craft.t('lens', 'Manually edited');
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
                meta.textContent = '';
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
    function init() {
        LensInlineEditor.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
