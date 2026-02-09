/**
 * Lens Plugin — Analysis Panel
 * Interactive asset intelligence hub: inline editing, tag/color editors,
 * apply actions, and safety flag dismissal.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    // ==========================================================================
    // Analysis Panel — Main controller
    // ==========================================================================

    var LensAnalysisPanel = {
        _initialized: false,
        _taxonomyDirty: false,
        _tagDebounce: null,

        init: function() {
            if (this._initialized) return;
            this._initialized = true;

            this.initInlineEditing();
            this.initTagEditor();
            this.initColorEditor();
            this.initTaxonomySave();
            this.initFlagDismissal();
            this.initAssetActions();
            this.initAutoPolling();
        },

        // ======================================================================
        // Inline field editing (title, alt text, description)
        // ======================================================================

        initInlineEditing: function() {
            // Enter edit mode
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('.lens-edit-trigger');
                if (!trigger) return;

                var field = trigger.closest('.lens-editable-field');
                if (!field) return;

                LensAnalysisPanel.enterEditMode(field);
            });

            // Save field
            document.addEventListener('click', function(e) {
                var saveBtn = e.target.closest('.lens-field-save');
                if (!saveBtn) return;

                var field = saveBtn.closest('.lens-editable-field');
                if (!field) return;

                LensAnalysisPanel.saveField(field);
            });

            // Cancel edit
            document.addEventListener('click', function(e) {
                var cancelBtn = e.target.closest('.lens-field-cancel');
                if (!cancelBtn) return;

                var field = cancelBtn.closest('.lens-editable-field');
                if (!field) return;

                LensAnalysisPanel.cancelEdit(field);
            });

            // Revert to AI
            document.addEventListener('click', function(e) {
                var revertBtn = e.target.closest('.lens-revert-trigger');
                if (!revertBtn) return;

                var field = revertBtn.closest('.lens-editable-field');
                if (!field) return;

                LensAnalysisPanel.revertField(field);
            });

            // People Detection - Enter edit mode
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('[data-action="people-edit"]');
                if (!trigger) return;

                var field = trigger.closest('.lens-people-detection');
                if (!field) return;

                LensAnalysisPanel.enterPeopleEditMode(field);
            });

            // People Detection - Save
            document.addEventListener('click', function(e) {
                var saveBtn = e.target.closest('[data-action="people-save"]');
                if (!saveBtn) return;

                var field = saveBtn.closest('.lens-people-detection');
                if (!field) return;

                LensAnalysisPanel.savePeopleDetection(field);
            });

            // People Detection - Cancel
            document.addEventListener('click', function(e) {
                var cancelBtn = e.target.closest('[data-action="people-cancel"]');
                if (!cancelBtn) return;

                var field = cancelBtn.closest('.lens-people-detection');
                if (!field) return;

                LensAnalysisPanel.cancelPeopleEdit(field);
            });

            // People Detection - Revert
            document.addEventListener('click', function(e) {
                var revertBtn = e.target.closest('[data-action="people-revert"]');
                if (!revertBtn) return;

                LensAnalysisPanel.revertPeopleDetection(revertBtn.dataset.analysisId);
            });
        },

        enterEditMode: function(fieldEl) {
            var display = fieldEl.querySelector('.lens-field-display');
            var edit = fieldEl.querySelector('.lens-field-edit');
            if (!display || !edit) return;

            display.style.display = 'none';
            edit.hidden = false;

            var input = edit.querySelector('input, textarea');
            if (input) {
                input.focus();
                input.select();
            }
        },

        cancelEdit: function(fieldEl) {
            var display = fieldEl.querySelector('.lens-field-display');
            var edit = fieldEl.querySelector('.lens-field-edit');
            if (!display || !edit) return;

            // Restore original value from display
            var displayText = fieldEl.querySelector('.lens-field-display p');
            var input = edit.querySelector('input, textarea');
            if (displayText && input) {
                input.value = displayText.textContent;
            }

            display.style.display = '';
            edit.hidden = true;
        },

        saveField: function(fieldEl) {
            var analysisId = fieldEl.dataset.analysisId;
            var fieldName = fieldEl.dataset.field;
            var input = fieldEl.querySelector('.lens-field-edit input, .lens-field-edit textarea');
            if (!input) return;

            var value = input.value;
            var saveBtn = fieldEl.querySelector('.lens-field-save');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = Craft.t('lens', 'Saving...');
            }

            Craft.sendActionRequest('POST', 'lens/analysis/update-field', {
                data: { analysisId: analysisId, field: fieldName, value: value }
            }).then(function(response) {
                if (response.data.success) {
                    // Update display text
                    var displayP = fieldEl.querySelector('.lens-field-display p');
                    if (displayP) {
                        displayP.textContent = response.data.value;
                    }

                    // Show lock icon
                    var header = fieldEl.querySelector('.lens-field-header');
                    if (header && !header.querySelector('.lens-lock-icon')) {
                        var lock = document.createElement('span');
                        lock.className = 'lens-lock-icon';
                        lock.title = Craft.t('lens', 'Protected from reprocessing');
                        lock.innerHTML = '&#128274;';
                        header.insertBefore(lock, header.firstChild);
                    }

                    // Remove confidence badge (field is now user-edited)
                    var badge = header ? header.querySelector('.badge') : null;
                    if (badge) badge.remove();

                    // Update edit meta
                    LensAnalysisPanel.updateEditMeta(fieldEl, response.data);

                    // Exit edit mode
                    var display = fieldEl.querySelector('.lens-field-display');
                    var edit = fieldEl.querySelector('.lens-field-edit');
                    if (display) display.style.display = '';
                    if (edit) edit.hidden = true;

                    Craft.cp.displayNotice(Craft.t('lens', 'Field updated.'));
                }
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Failed to update field.'));
            }).finally(function() {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = Craft.t('lens', 'Save');
                }
            });
        },

        revertField: function(fieldEl) {
            var analysisId = fieldEl.dataset.analysisId;
            var fieldName = fieldEl.dataset.field;

            Craft.sendActionRequest('POST', 'lens/analysis/revert-field', {
                data: { analysisId: analysisId, field: fieldName }
            }).then(function(response) {
                if (response.data.success) {
                    // Update display text
                    var displayP = fieldEl.querySelector('.lens-field-display p');
                    if (displayP) {
                        displayP.textContent = response.data.value;
                    }

                    // Update edit input too
                    var input = fieldEl.querySelector('.lens-field-edit input, .lens-field-edit textarea');
                    if (input) {
                        input.value = response.data.value;
                    }

                    // Remove lock icon
                    var lock = fieldEl.querySelector('.lens-lock-icon');
                    if (lock) lock.remove();

                    // Remove edit meta and AI suggestion
                    var editMeta = fieldEl.querySelector('.lens-edit-meta');
                    if (editMeta) editMeta.remove();
                    var aiSuggestion = fieldEl.querySelector('.lens-ai-suggestion');
                    if (aiSuggestion) aiSuggestion.remove();

                    Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI value.'));
                }
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Failed to revert field.'));
            });
        },

        updateEditMeta: function(fieldEl, data) {
            // Remove existing meta
            var existing = fieldEl.querySelector('.lens-edit-meta');
            if (existing) existing.remove();
            var existingAi = fieldEl.querySelector('.lens-ai-suggestion');
            if (existingAi) existingAi.remove();

            // Add edit meta
            if (data.editedBy) {
                var meta = document.createElement('div');
                meta.className = 'lens-edit-meta';
                meta.textContent = Craft.t('lens', 'Edited by {user} on {date}', {
                    user: data.editedBy,
                    date: data.editedAt
                });
                fieldEl.appendChild(meta);
            }

            // Add AI suggestion reference
            if (data.aiValue && data.aiValue !== data.value) {
                var aiDiv = document.createElement('div');
                aiDiv.className = 'lens-ai-suggestion';

                var aiText = document.createTextNode(
                    Craft.t('lens', 'AI suggested: "{value}"', {
                        value: data.aiValue.length > 100 ? data.aiValue.substring(0, 100) + '...' : data.aiValue
                    }) + ' '
                );
                aiDiv.appendChild(aiText);

                var revertBtn = document.createElement('button');
                revertBtn.type = 'button';
                revertBtn.className = 'lens-revert-trigger';
                revertBtn.dataset.field = fieldEl.dataset.field;
                revertBtn.dataset.analysisId = fieldEl.dataset.analysisId;
                revertBtn.textContent = Craft.t('lens', 'Revert to AI');
                aiDiv.appendChild(revertBtn);

                fieldEl.appendChild(aiDiv);
            }
        },

        // ======================================================================
        // People Detection Editor
        // ======================================================================

        enterPeopleEditMode: function(fieldEl) {
            var display = fieldEl.querySelector('.lens-field-display');
            var edit = fieldEl.querySelector('.lens-people-edit-panel');
            if (!display || !edit) return;

            // Get current values
            var containsPeople = fieldEl.dataset.containsPeople === '1';
            var faceCount = parseInt(fieldEl.dataset.faceCount, 10) || 0;

            // Select appropriate radio button based on DAM tiers
            var radios = edit.querySelectorAll('[data-control="people-mode"]');
            var selectedValue;

            if (!containsPeople) {
                selectedValue = 'none';
            } else if (faceCount === 0) {
                selectedValue = 'no-faces';
            } else if (faceCount === 1) {
                selectedValue = '1';
            } else if (faceCount === 2) {
                selectedValue = '2';
            } else if (faceCount >= 3 && faceCount <= 5) {
                selectedValue = '3-5';
            } else if (faceCount >= 6) {
                selectedValue = '6+';
            }

            radios.forEach(function(radio) {
                radio.checked = (radio.value === selectedValue);
            });

            display.style.display = 'none';
            edit.hidden = false;
        },

        cancelPeopleEdit: function(fieldEl) {
            var display = fieldEl.querySelector('.lens-field-display');
            var edit = fieldEl.querySelector('.lens-people-edit-panel');
            if (!display || !edit) return;

            display.style.display = '';
            edit.hidden = true;
        },

        savePeopleDetection: function(fieldEl) {
            var analysisId = fieldEl.dataset.analysisId;
            var edit = fieldEl.querySelector('.lens-people-edit-panel');
            if (!edit) return;

            // Get selected mode
            var selectedRadio = edit.querySelector('[data-control="people-mode"]:checked');
            if (!selectedRadio) {
                Craft.cp.displayError(Craft.t('lens', 'Please select an option'));
                return;
            }

            var mode = selectedRadio.value;
            var containsPeople, faceCount;

            // Map DAM tier values to face counts
            switch (mode) {
                case 'none':
                    containsPeople = false;
                    faceCount = 0;
                    break;
                case 'no-faces':
                    containsPeople = true;
                    faceCount = 0;
                    break;
                case '1':
                    containsPeople = true;
                    faceCount = 1;
                    break;
                case '2':
                    containsPeople = true;
                    faceCount = 2;
                    break;
                case '3-5':
                    containsPeople = true;
                    faceCount = 4; // Midpoint of range
                    break;
                case '6+':
                    containsPeople = true;
                    faceCount = 6; // Minimum of "6 or more"
                    break;
                default:
                    Craft.cp.displayError(Craft.t('lens', 'Invalid selection'));
                    return;
            }

            var saveBtn = edit.querySelector('[data-action="people-save"]');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = Craft.t('lens', 'Saving...');
            }

            // Save both fields (Promise.all ensures atomicity)
            var promises = [
                Craft.sendActionRequest('POST', 'lens/analysis/update-field', {
                    data: { analysisId: analysisId, field: 'containsPeople', value: containsPeople }
                }),
                Craft.sendActionRequest('POST', 'lens/analysis/update-field', {
                    data: { analysisId: analysisId, field: 'faceCount', value: faceCount }
                })
            ];

            Promise.all(promises).then(function(responses) {
                // Update data attributes
                fieldEl.dataset.containsPeople = containsPeople ? '1' : '0';
                fieldEl.dataset.faceCount = faceCount;

                // Update display structure based on new state
                var display = fieldEl.querySelector('.lens-field-display');
                if (display) {
                    var newHtml = '';

                    if (containsPeople) {
                        // People detected - show tier with status on
                        newHtml += '<div class="lens-detection-item"><div>';
                        newHtml += '<div class="flex items-center gap-s">';
                        newHtml += '<span class="status on"></span>';
                        newHtml += '<strong class="lens-people-display-text">';

                        if (faceCount === 0) {
                            newHtml += Craft.t('lens', 'People detected (no visible faces)');
                        } else if (faceCount === 1) {
                            newHtml += Craft.t('lens', 'Individual (1 person)');
                        } else if (faceCount === 2) {
                            newHtml += Craft.t('lens', 'Duo (2 people)');
                        } else if (faceCount >= 3 && faceCount <= 5) {
                            newHtml += Craft.t('lens', 'Small group (3-5 people)');
                        } else if (faceCount >= 6) {
                            newHtml += Craft.t('lens', 'Large group (6+ people)');
                        }

                        newHtml += '</strong></div>';
                        newHtml += '<p class="smalltext light lens-people-help-text" style="margin: 4px 0 0">';
                        if (faceCount > 0) {
                            newHtml += Craft.t('lens', 'May require model releases for commercial use');
                        } else {
                            newHtml += Craft.t('lens', 'People visible but faces obscured. May still require releases for commercial use.');
                        }
                        newHtml += '</p>';
                        newHtml += '</div></div>';
                    } else {
                        // No people detected - show empty state with status off
                        newHtml += '<div class="lens-detection-item lens-detection-empty">';
                        newHtml += '<div class="flex items-center gap-s">';
                        newHtml += '<span class="status off"></span>';
                        newHtml += '<span class="lens-people-display-text">' + Craft.t('lens', 'No people detected') + '</span>';
                        newHtml += '</div></div>';
                    }

                    display.innerHTML = newHtml;
                }

                // Show lock icon if not already present
                var header = fieldEl.querySelector('.lens-field-header');
                if (header && !header.querySelector('.lens-lock-icon')) {
                    var lock = document.createElement('span');
                    lock.className = 'lens-lock-icon';
                    lock.title = Craft.t('lens', 'Protected from reprocessing');
                    lock.innerHTML = '&#128274;';
                    header.insertBefore(lock, header.firstChild);
                }

                // Update edit meta with most recent response (faceCount)
                var faceCountResponse = responses[1].data;
                if (faceCountResponse.editedBy) {
                    var existing = fieldEl.querySelector('.lens-edit-meta');
                    if (existing) existing.remove();

                    var meta = document.createElement('div');
                    meta.className = 'lens-edit-meta';
                    meta.textContent = Craft.t('lens', 'Edited by {user} on {date}', {
                        user: faceCountResponse.editedBy,
                        date: faceCountResponse.editedAt
                    });
                    fieldEl.appendChild(meta);
                }

                // Check if AI suggestion should be shown
                var containsPeopleAi = fieldEl.dataset.containsPeopleAi === '1';
                var faceCountAi = parseInt(fieldEl.dataset.faceCountAi) || 0;
                var aiDiffers = (containsPeopleAi !== containsPeople) || (faceCountAi !== faceCount);

                // Remove existing AI suggestion
                var existingSuggestion = fieldEl.querySelector('.lens-ai-suggestion');
                if (existingSuggestion) existingSuggestion.remove();

                // Show AI suggestion if values differ
                if (aiDiffers) {
                    var suggestion = document.createElement('div');
                    suggestion.className = 'lens-ai-suggestion';

                    var aiText = Lens.utils.formatPeopleDetectionText(containsPeopleAi, faceCountAi);
                    var suggestionText = Craft.t('lens', 'AI suggested: "{text}"', {text: aiText});

                    suggestion.innerHTML = suggestionText +
                        ' <button type="button" data-action="people-revert" data-analysis-id="' + analysisId + '">' +
                        Craft.t('lens', 'Revert to AI') +
                        '</button>';

                    fieldEl.appendChild(suggestion);
                }

                // Exit edit mode
                var display = fieldEl.querySelector('.lens-field-display');
                var editPanel = fieldEl.querySelector('.lens-people-edit-panel');
                if (display) display.style.display = '';
                if (editPanel) editPanel.hidden = true;

                // Dispatch custom event for review area to update currentData
                document.dispatchEvent(new CustomEvent('lens:peopleDetectionUpdated', {
                    detail: {
                        analysisId: analysisId,
                        containsPeople: containsPeople,
                        faceCount: faceCount
                    }
                }));

                Craft.cp.displayNotice(Craft.t('lens', 'People detection updated.'));
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Failed to update people detection.'));
            }).finally(function() {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = Craft.t('lens', 'Save');
                }
            });
        },

        revertPeopleDetection: function(analysisId) {
            var promises = [
                Craft.sendActionRequest('POST', 'lens/analysis/revert-field', {
                    data: { analysisId: analysisId, field: 'containsPeople' }
                }),
                Craft.sendActionRequest('POST', 'lens/analysis/revert-field', {
                    data: { analysisId: analysisId, field: 'faceCount' }
                })
            ];

            Promise.all(promises).then(function() {
                Craft.cp.displayNotice(Craft.t('lens', 'Reverted to AI values. Refreshing...'));
                setTimeout(function() { window.location.reload(); }, 500);
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Failed to revert people detection.'));
            });
        },

        // ======================================================================
        // Tag Editor
        // ======================================================================

        initTagEditor: function() {
            // Add tag via button or Enter key
            document.addEventListener('click', function(e) {
                var addBtn = e.target.closest('.lens-tag-add');
                if (!addBtn) return;

                var editor = addBtn.closest('.lens-tag-editor');
                if (!editor) return;

                LensAnalysisPanel.addTagFromInput(editor);
            });

            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter') return;
                var input = e.target.closest('.lens-tag-input');
                if (!input) return;

                e.preventDefault();

                // Check if a suggestion is active
                var editor = input.closest('.lens-tag-editor');
                var activeSuggestion = editor ? editor.querySelector('.lens-tag-suggestion.is-active') : null;
                if (activeSuggestion) {
                    LensAnalysisPanel.selectTagSuggestion(editor, activeSuggestion);
                } else {
                    LensAnalysisPanel.addTagFromInput(editor);
                }
            });

            // Remove tag
            document.addEventListener('click', function(e) {
                var removeBtn = e.target.closest('.lens-tag-remove');
                if (!removeBtn) return;

                var chip = removeBtn.closest('.chip');
                if (!chip) return;

                chip.remove();
                LensAnalysisPanel.markTaxonomyDirty(removeBtn);
            });

            // Tag autocomplete
            document.addEventListener('input', function(e) {
                var input = e.target.closest('.lens-tag-input');
                if (!input) return;

                var query = input.value.trim();
                var editor = input.closest('.lens-tag-editor');
                if (!editor) return;

                if (query.length < 2) {
                    LensAnalysisPanel.hideTagSuggestions(editor);
                    return;
                }

                LensAnalysisPanel.loadTagSuggestions(editor, query);
            });

            // Click on suggestion
            document.addEventListener('click', function(e) {
                var suggestion = e.target.closest('.lens-tag-suggestion');
                if (!suggestion) return;

                var editor = suggestion.closest('.lens-tag-editor');
                if (!editor) return;

                LensAnalysisPanel.selectTagSuggestion(editor, suggestion);
            });

            // Navigate suggestions with arrow keys
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
                var input = e.target.closest('.lens-tag-input');
                if (!input) return;

                var editor = input.closest('.lens-tag-editor');
                var suggestionsEl = editor ? editor.querySelector('.lens-tag-suggestions') : null;
                if (!suggestionsEl || !suggestionsEl.classList.contains('is-visible')) return;

                e.preventDefault();
                var items = suggestionsEl.querySelectorAll('.lens-tag-suggestion');
                var active = suggestionsEl.querySelector('.lens-tag-suggestion.is-active');
                var idx = active ? Array.prototype.indexOf.call(items, active) : -1;

                if (active) active.classList.remove('is-active');

                if (e.key === 'ArrowDown') {
                    idx = (idx + 1) % items.length;
                } else {
                    idx = idx <= 0 ? items.length - 1 : idx - 1;
                }

                items[idx].classList.add('is-active');
            });

            // Hide suggestions on blur (with delay for click)
            document.addEventListener('focusout', function(e) {
                var input = e.target.closest('.lens-tag-input');
                if (!input) return;

                setTimeout(function() {
                    var editor = input.closest('.lens-tag-editor');
                    if (editor) LensAnalysisPanel.hideTagSuggestions(editor);
                }, 200);
            });
        },

        addTagFromInput: function(editor) {
            var input = editor.querySelector('.lens-tag-input');
            if (!input) return;

            var tagName = input.value.trim();
            if (!tagName) return;

            // Check for duplicates
            var existing = editor.querySelectorAll('.chip');
            for (var i = 0; i < existing.length; i++) {
                if (existing[i].dataset.tag.toLowerCase() === tagName.toLowerCase()) {
                    input.value = '';
                    return;
                }
            }

            this.addTagChip(editor, tagName, false);
            input.value = '';
            this.hideTagSuggestions(editor);
            this.markTaxonomyDirty(editor);
        },

        addTagChip: function(editor, tagName, isAi) {
            var chips = editor.querySelector('.lens-tag-chips');
            if (!chips) {
                // Create chips container if it doesn't exist (was showing "No tags")
                var noTags = editor.querySelector('p.light');
                if (noTags) noTags.remove();

                chips = document.createElement('div');
                chips.className = 'lens-tag-chips';
                var labelRow = editor.querySelector('.flex');
                if (labelRow) {
                    labelRow.parentNode.insertBefore(chips, labelRow.nextSibling);
                }
            }

            var chip = document.createElement('div');
            chip.className = 'chip' + (isAi ? ' chip--ai' : '');
            chip.dataset.tag = tagName;
            chip.dataset.isAi = isAi ? '1' : '0';
            chip.dataset.confidence = isAi ? '' : '1';
            chip.innerHTML =
                '<div class="chip-content">' +
                    '<span class="chip-label">' + Craft.escapeHtml(tagName) + '</span>' +
                    '<button type="button" class="lens-tag-remove" title="' + Craft.t('lens', 'Remove') + '">&times;</button>' +
                '</div>';

            chips.appendChild(chip);
        },

        selectTagSuggestion: function(editor, suggestion) {
            var tagName = suggestion.dataset.tag;
            var input = editor.querySelector('.lens-tag-input');
            if (input) input.value = '';

            // Check for duplicates
            var existing = editor.querySelectorAll('.chip');
            for (var i = 0; i < existing.length; i++) {
                if (existing[i].dataset.tag.toLowerCase() === tagName.toLowerCase()) {
                    this.hideTagSuggestions(editor);
                    return;
                }
            }

            this.addTagChip(editor, tagName, false);
            this.hideTagSuggestions(editor);
            this.markTaxonomyDirty(editor);
        },

        loadTagSuggestions: function(editor, query) {
            if (this._tagDebounce) clearTimeout(this._tagDebounce);

            this._tagDebounce = setTimeout(function() {
                Craft.sendActionRequest('GET', 'lens/analysis/tag-suggestions', {
                    params: { query: query }
                }).then(function(response) {
                    var tags = response.data.tags || [];
                    LensAnalysisPanel.showTagSuggestions(editor, tags);
                });
            }, 250);
        },

        showTagSuggestions: function(editor, tags) {
            var suggestionsEl = editor.querySelector('.lens-tag-suggestions');
            if (!suggestionsEl) return;

            if (!tags.length) {
                suggestionsEl.classList.remove('is-visible');
                return;
            }

            suggestionsEl.innerHTML = '';
            for (var i = 0; i < tags.length; i++) {
                var item = document.createElement('div');
                item.className = 'lens-tag-suggestion';
                item.dataset.tag = tags[i].tag;
                item.innerHTML =
                    '<span>' + Craft.escapeHtml(tags[i].tag) + '</span>' +
                    '<span class="lens-tag-suggestion-count">' + tags[i].count + '</span>';
                suggestionsEl.appendChild(item);
            }
            suggestionsEl.classList.add('is-visible');
        },

        hideTagSuggestions: function(editor) {
            var suggestionsEl = editor.querySelector('.lens-tag-suggestions');
            if (suggestionsEl) suggestionsEl.classList.remove('is-visible');
        },

        // ======================================================================
        // Color Editor
        // ======================================================================

        initColorEditor: function() {
            // Add color
            document.addEventListener('click', function(e) {
                var addBtn = e.target.closest('.lens-color-add');
                if (!addBtn) return;

                var editor = addBtn.closest('.lens-color-editor');
                if (!editor) return;

                var picker = editor.querySelector('.lens-color-picker');
                if (!picker) return;

                var hex = picker.value;
                LensAnalysisPanel.addColorSwatch(editor, hex);
                LensAnalysisPanel.markTaxonomyDirty(editor);
            });

            // Remove color
            document.addEventListener('click', function(e) {
                var removeBtn = e.target.closest('.lens-color-remove');
                if (!removeBtn) return;

                var item = removeBtn.closest('.lens-color-item');
                if (!item) return;

                item.remove();
                LensAnalysisPanel.markTaxonomyDirty(removeBtn);
            });
        },

        addColorSwatch: function(editor, hex) {
            // Validate hex
            if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) return;

            // Check for duplicates
            var existing = editor.querySelectorAll('.lens-color-item');
            for (var i = 0; i < existing.length; i++) {
                if (existing[i].dataset.hex.toLowerCase() === hex.toLowerCase()) return;
            }

            var swatches = editor.querySelector('.lens-color-swatches');
            if (!swatches) {
                var noColors = editor.querySelector('p.light');
                if (noColors) noColors.remove();

                swatches = document.createElement('div');
                swatches.className = 'lens-color-swatches';
                var labelRow = editor.querySelector('.flex');
                if (labelRow) {
                    labelRow.parentNode.insertBefore(swatches, labelRow.nextSibling);
                }
            }

            var item = document.createElement('div');
            item.className = 'lens-color-item';
            item.dataset.hex = hex;
            item.dataset.isAi = '0';
            item.dataset.percentage = '';
            item.innerHTML =
                '<span class="lens-swatch lens-swatch--sm" style="background-color: ' + hex + '"></span>' +
                '<span>' + hex + '</span>' +
                '<button type="button" class="lens-color-remove" title="' + Craft.t('lens', 'Remove') + '">&times;</button>';

            swatches.appendChild(item);
        },

        // ======================================================================
        // Taxonomy Save (tags + colors batch)
        // ======================================================================

        markTaxonomyDirty: function(el) {
            var section = el.closest('.lens-section');
            if (!section) return;

            var saveBtn = section.querySelector('.lens-taxonomy-save-btn');
            if (saveBtn) {
                saveBtn.disabled = false;
                var status = section.querySelector('.lens-taxonomy-status');
                if (status) status.textContent = Craft.t('lens', 'Unsaved changes');
            }
        },

        initTaxonomySave: function() {
            document.addEventListener('click', function(e) {
                var saveBtn = e.target.closest('.lens-taxonomy-save-btn');
                if (!saveBtn || saveBtn.disabled) return;

                var analysisId = saveBtn.dataset.analysisId;
                var section = saveBtn.closest('.lens-section');
                if (!section) return;

                saveBtn.disabled = true;
                saveBtn.textContent = Craft.t('lens', 'Saving...');

                // Collect tags
                var tags = [];
                var tagChips = section.querySelectorAll('.lens-tag-chips .chip');
                for (var i = 0; i < tagChips.length; i++) {
                    tags.push({
                        tag: tagChips[i].dataset.tag,
                        isAi: tagChips[i].dataset.isAi === '1',
                        confidence: tagChips[i].dataset.confidence ? parseFloat(tagChips[i].dataset.confidence) : null
                    });
                }

                // Collect colors
                var colors = [];
                var colorItems = section.querySelectorAll('.lens-color-item');
                for (var j = 0; j < colorItems.length; j++) {
                    colors.push({
                        hex: colorItems[j].dataset.hex,
                        isAi: colorItems[j].dataset.isAi === '1',
                        percentage: colorItems[j].dataset.percentage ? parseFloat(colorItems[j].dataset.percentage) : null
                    });
                }

                // Save both
                var tagPromise = Craft.sendActionRequest('POST', 'lens/analysis/update-tags', {
                    data: { analysisId: analysisId, tags: JSON.stringify(tags) }
                });

                var colorPromise = Craft.sendActionRequest('POST', 'lens/analysis/update-colors', {
                    data: { analysisId: analysisId, colors: JSON.stringify(colors) }
                });

                Promise.all([tagPromise, colorPromise]).then(function() {
                    Craft.cp.displayNotice(Craft.t('lens', 'Taxonomy saved.'));
                    var status = section.querySelector('.lens-taxonomy-status');
                    if (status) status.textContent = '';
                    saveBtn.textContent = Craft.t('lens', 'Save Changes');
                }).catch(function() {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to save taxonomy.'));
                    saveBtn.disabled = false;
                    saveBtn.textContent = Craft.t('lens', 'Save Changes');
                });
            });
        },

        // ======================================================================
        // Safety Flag Dismissal
        // ======================================================================

        initFlagDismissal: function() {
            document.addEventListener('click', function(e) {
                var dismissBtn = e.target.closest('.lens-flag-dismiss');
                if (!dismissBtn) return;

                var analysisId = dismissBtn.dataset.analysisId;
                var field = dismissBtn.dataset.field;
                var value = dismissBtn.dataset.value;

                // For boolean fields, use update-field; for scores, set to 0
                var sendValue = value !== undefined ? value : false;

                dismissBtn.disabled = true;
                dismissBtn.textContent = Craft.t('lens', 'Dismissing...');

                Craft.sendActionRequest('POST', 'lens/analysis/update-field', {
                    data: { analysisId: analysisId, field: field, value: sendValue }
                }).then(function(response) {
                    if (response.data.success) {
                        // Remove the detection item
                        var detectionItem = dismissBtn.closest('.lens-detection-item');
                        if (detectionItem) {
                            detectionItem.style.opacity = '0.3';
                            setTimeout(function() { detectionItem.remove(); }, 300);
                        }
                        Craft.cp.displayNotice(Craft.t('lens', 'Flag cleared.'));
                    }
                }).catch(function() {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to clear flag.'));
                    dismissBtn.disabled = false;
                    dismissBtn.textContent = Craft.t('lens', 'Dismiss');
                });
            });
        },

        // ======================================================================
        // Asset Actions (analyze, apply title, apply focal point, find similar)
        // ======================================================================

        initAssetActions: function() {
            // Analyze / Reprocess
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-lens-analyze], [data-lens-reprocess]');
                if (!btn || btn.disabled) return;

                var assetId = btn.dataset.lensAnalyze || btn.dataset.lensReprocess;
                var originalText = btn.textContent;

                btn.disabled = true;
                btn.textContent = Craft.t('lens', 'Processing...');

                Craft.sendActionRequest('POST', 'lens/analysis/reprocess', {
                    data: { assetId: assetId }
                }).then(function(response) {
                    if (response.data.success) {
                        Craft.cp.displayNotice(Craft.t('lens', 'Asset queued for analysis.'));
                        btn.textContent = Craft.t('lens', 'Analyzing...');
                        LensAnalysisPanel.pollForAnalysisCompletion(assetId);
                    } else {
                        Craft.cp.displayError(Craft.t('lens', 'Failed to queue asset.'));
                        btn.disabled = false;
                        btn.textContent = originalText;
                    }
                }).catch(function(err) {
                    console.error('Lens: Failed to reprocess asset', err);
                    Craft.cp.displayError(Craft.t('lens', 'Failed to queue asset.'));
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
            });

            // Apply Title
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-lens-apply-title]');
                if (!btn || btn.disabled) return;

                var assetId = btn.dataset.lensApplyTitle;
                var originalText = btn.textContent;

                btn.disabled = true;
                btn.textContent = Craft.t('lens', 'Applying...');

                Craft.sendActionRequest('POST', 'lens/analysis/apply-title', {
                    data: { assetId: assetId }
                }).then(function(response) {
                    if (response.data.success) {
                        Craft.cp.displayNotice(Craft.t('lens', 'Title applied.'));
                        window.location.reload();
                    }
                }).catch(function() {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to apply title.'));
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
            });

            // Apply Focal Point
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-lens-apply-focal-point]');
                if (!btn || btn.disabled) return;

                var assetId = btn.dataset.lensApplyFocalPoint;
                var originalText = btn.textContent;

                btn.disabled = true;
                btn.textContent = Craft.t('lens', 'Applying...');

                Craft.sendActionRequest('POST', 'lens/analysis/apply-focal-point', {
                    data: { assetId: assetId }
                }).then(function(response) {
                    if (response.data.success) {
                        Craft.cp.displayNotice(Craft.t('lens', 'Focal point applied.'));
                        window.location.reload();
                    }
                }).catch(function() {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to apply focal point.'));
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
            });

            // Find Similar
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('[data-lens-find-similar]');
                if (!btn) return;

                var assetId = btn.dataset.lensFindSimilar;
                window.location.href = Craft.getCpUrl('lens/search', { duplicateOf: assetId });
            });
        },

        /**
         * Auto-start polling if an analysis is in progress when the page loads.
         */
        initAutoPolling: function() {
            var panel = document.querySelector('.lens-analysis-panel');
            if (!panel) return;

            var status = panel.dataset.analysisStatus;
            var assetId = panel.dataset.assetId;

            // If analysis is pending or processing, start polling
            if (status === 'pending' || status === 'processing') {
                console.log('Lens: Auto-starting polling for asset', assetId, 'with status', status);
                this.pollForAnalysisCompletion(parseInt(assetId, 10));
            }
        },

        /**
         * Poll for analysis completion with exponential backoff and page visibility check.
         */
        pollForAnalysisCompletion: function(assetId) {
            var intervals = [3000, 5000, 8000, 10000, 15000, 20000, 30000, 60000];
            var maxAttempts = 40;
            var attempts = 0;

            var checkStatus = function() {
                if (document.hidden) {
                    scheduleNext();
                    return;
                }

                attempts++;

                Craft.sendActionRequest('GET', 'lens/analysis/get-status', {
                    params: { assetId: assetId }
                }).then(function(response) {
                    var status = response.data.status;

                    if (status === 'error') {
                        console.error('Lens: Analysis status error', response.data);
                    }

                    if (status === 'completed' || status === 'approved' || status === 'failed') {
                        var message = status === 'failed'
                            ? Craft.t('lens', 'Analysis failed. Refreshing to show error...')
                            : Craft.t('lens', 'Analysis complete. Refreshing...');
                        Craft.cp.displayNotice(message);
                        setTimeout(function() { window.location.reload(); }, 1000);
                    } else if (attempts < maxAttempts && (status === 'not_found' || status === 'pending' || status === 'processing' || status === 'pending_review')) {
                        scheduleNext();
                    } else if (attempts >= maxAttempts) {
                        Craft.cp.displayNotice(Craft.t('lens', 'Analysis is taking longer than expected. Please refresh manually.'));
                    }
                }).catch(function(err) {
                    console.error('Lens: Failed to check analysis status', err);
                    if (attempts < 3) {
                        scheduleNext();
                    } else {
                        var errorMsg = err.response && err.response.data && err.response.data.error
                            ? err.response.data.error
                            : 'Failed to check analysis status. Please refresh manually.';
                        Craft.cp.displayError(Craft.t('lens', errorMsg));
                    }
                });
            };

            var scheduleNext = function() {
                var intervalIndex = Math.min(attempts, intervals.length - 1);
                var interval = intervals[intervalIndex];
                setTimeout(checkStatus, interval);
            };

            setTimeout(checkStatus, intervals[0]);
        }
    };

    // Export modules
    window.Lens.AnalysisPanel = LensAnalysisPanel;
    window.Lens.AssetActions = LensAnalysisPanel; // Backward compat alias

    // ==========================================================================
    // Initialize when DOM is ready
    // ==========================================================================

    function init() {
        LensAnalysisPanel.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
