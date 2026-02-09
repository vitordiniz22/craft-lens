/**
 * Lens Plugin - Review
 * Two-panel focused review interface and review queue
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    // ==========================================================================
    // Review Queue
    // ==========================================================================

    var LensReviewQueue = {
        init: function() {
            this.initBulkSelection();
            this.initTagConfidence();
            this.initTagEditors();
            this.initColorEditors();
            this.initEditableFields();
        },

        initBulkSelection: function() {
            var selectAllBtn = document.getElementById('lens-select-all');
            var approveSelectedBtn = document.getElementById('lens-approve-selected');
            var bulkIdsInput = document.getElementById('lens-bulk-ids');
            var checkboxes = document.querySelectorAll('.lens-review-select');

            if (!selectAllBtn || !approveSelectedBtn || !bulkIdsInput || checkboxes.length === 0) {
                return;
            }

            function updateBulkState() {
                var selected = Array.from(checkboxes).filter(function(cb) { return cb.checked; }).map(function(cb) { return cb.value; });
                bulkIdsInput.value = selected.join(',');
                approveSelectedBtn.disabled = selected.length === 0;
            }

            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', updateBulkState);
            });

            selectAllBtn.addEventListener('click', function() {
                var allSelected = Array.from(checkboxes).every(function(cb) { return cb.checked; });
                checkboxes.forEach(function(cb) { cb.checked = !allSelected; });
                updateBulkState();
            });
        },

        initTagConfidence: function() {
            // Set confidence as CSS variable for opacity
            document.querySelectorAll('.lens-tag[data-confidence]').forEach(function(tag) {
                tag.style.setProperty('--confidence', tag.dataset.confidence);
            });
        },

        initTagEditors: function() {
            document.querySelectorAll('.lens-tag-editor').forEach(function(editor) {
                var hiddenInput = editor.closest('.field').querySelector('.lens-tag-data');
                var tagInput = editor.querySelector('.lens-tag-input');
                if (!hiddenInput || !tagInput) return;

                function syncHiddenInput() {
                    var tags = [];
                    editor.querySelectorAll('.lens-tag').forEach(function(tagEl) {
                        tags.push({
                            tag: tagEl.dataset.tag,
                            confidence: parseFloat(tagEl.dataset.confidence) || 1.0,
                            isAi: tagEl.dataset.isAi === '1'
                        });
                    });
                    hiddenInput.value = JSON.stringify(tags);
                }

                // Remove tag
                editor.addEventListener('click', function(e) {
                    var removeBtn = e.target.closest('.lens-tag-remove');
                    if (removeBtn) {
                        removeBtn.closest('.lens-tag').remove();
                        syncHiddenInput();
                    }
                });

                // Add tag on Enter
                tagInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var value = tagInput.value.trim().toLowerCase();
                        if (!value) return;

                        // Check for duplicates
                        var existing = editor.querySelectorAll('.lens-tag');
                        for (var i = 0; i < existing.length; i++) {
                            if (existing[i].dataset.tag === value) return;
                        }

                        var span = document.createElement('span');
                        span.className = 'lens-tag lens-tag--editable lens-tag--user';
                        span.dataset.tag = value;
                        span.dataset.confidence = '1.0';
                        span.dataset.isAi = '0';
                        span.innerHTML = value + ' <button type="button" class="lens-tag-remove" aria-label="Remove">&times;</button>';

                        editor.insertBefore(span, tagInput);
                        tagInput.value = '';
                        syncHiddenInput();
                    }
                });
            });
        },

        initColorEditors: function() {
            document.querySelectorAll('.lens-color-editor').forEach(function(editor) {
                var hiddenInput = editor.closest('.field').querySelector('.lens-color-data');
                if (!hiddenInput) return;

                function syncHiddenInput() {
                    var colors = [];
                    editor.querySelectorAll('.lens-color-item').forEach(function(item) {
                        colors.push({
                            hex: item.dataset.hex,
                            percentage: parseFloat(item.dataset.percentage) || 0,
                            isAi: item.dataset.isAi === '1'
                        });
                    });
                    hiddenInput.value = JSON.stringify(colors);
                }

                // Color picker change
                editor.addEventListener('input', function(e) {
                    if (e.target.classList.contains('lens-color-picker')) {
                        var item = e.target.closest('.lens-color-item');
                        var hex = e.target.value.toUpperCase();
                        item.dataset.hex = hex;
                        item.dataset.isAi = '0';
                        item.classList.add('lens-color--user');
                        item.querySelector('.lens-color-hex').textContent = hex;
                        syncHiddenInput();
                    }
                });

                // Remove color
                editor.addEventListener('click', function(e) {
                    var removeBtn = e.target.closest('.lens-color-remove');
                    if (removeBtn) {
                        removeBtn.closest('.lens-color-item').remove();
                        syncHiddenInput();
                    }

                    // Add color
                    var addBtn = e.target.closest('.lens-color-add');
                    if (addBtn) {
                        var div = document.createElement('div');
                        div.className = 'lens-color-item lens-color--user';
                        div.dataset.hex = '#000000';
                        div.dataset.percentage = '0';
                        div.dataset.isAi = '0';
                        div.innerHTML = '<input type="color" class="lens-color-picker" value="#000000">' +
                            '<span class="lens-color-hex">#000000</span>' +
                            '<button type="button" class="lens-color-remove" aria-label="Remove">&times;</button>';
                        editor.insertBefore(div, addBtn);
                        syncHiddenInput();
                    }
                });
            });
        },

        initEditableFields: function() {
            // When a text field or textarea in the review form is edited,
            // include it in the form submission by ensuring it has the right name
            document.querySelectorAll('.lens-action-form').forEach(function(form) {
                form.addEventListener('submit', function() {
                    var reviewItem = form.closest('.lens-review-item');
                    if (!reviewItem) return;

                    // Copy editable field values into the form as hidden inputs
                    reviewItem.querySelectorAll('.lens-editable').forEach(function(input) {
                        var name = input.getAttribute('name');
                        if (name) {
                            var hidden = form.querySelector('input[name="' + name + '"]');
                            if (!hidden) {
                                hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = name;
                                form.appendChild(hidden);
                            }
                            hidden.value = input.value;
                        }
                    });

                    // Copy tag and color data
                    reviewItem.querySelectorAll('.lens-tag-data, .lens-color-data').forEach(function(input) {
                        var name = input.getAttribute('name');
                        if (name) {
                            var hidden = form.querySelector('input[name="' + name + '"]');
                            if (!hidden) {
                                hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = name;
                                form.appendChild(hidden);
                            }
                            hidden.value = input.value;
                        }
                    });
                });
            });
        }
    };

    // ==========================================================================
    // Single Review (Two-Panel Focused Review)
    // ==========================================================================

    var LensSingleReview = {
        HIGH_CONFIDENCE_THRESHOLD: 0.8,
        ZOOM_STEPS: [0.25, 0.5, 0.75, 1, 1.5, 2, 3],

        // State
        queueIds: [],
        currentIndex: 0,
        currentData: null,
        isLoading: false,
        zoomLevel: 3, // index into ZOOM_STEPS (1 = 100%)
        mode: 'browse', // 'browse' | 'single' | 'grid'
        gridItems: [],
        gridSelection: new Set(),
        focalPointChanged: false,
        focalX: null,
        focalY: null,
        browsePage: 1,
        browsePerPage: 24,
        browseTotalPages: 0,

        init: function() {
            if (!window.__lensReviewData) return;

            var bootstrap = window.__lensReviewData;
            this.queueIds = bootstrap.queueIds.slice();
            this.currentIndex = 0;

            this.bindListeners();

            // Start in browse mode (default) instead of auto-loading first analysis
            var startMode = bootstrap.startMode || 'browse';
            if (startMode === 'browse') {
                this.loadBrowseView(1);
            } else if (bootstrap.firstAnalysisId) {
                this.switchMode('single');
                this.loadAnalysis(bootstrap.firstAnalysisId);
            }
        },

        // ==================================================================
        // Event Binding
        // ==================================================================

        bindListeners: function() {
            var self = this;

            // Action buttons
            var approveBtn = document.getElementById('lens-approve-btn');
            var skipBtn = document.getElementById('lens-skip-btn');
            var rejectBtn = document.getElementById('lens-reject-btn');
            var prevBtn = document.getElementById('lens-prev-btn');
            var nextBtn = document.getElementById('lens-next-btn');

            if (approveBtn) approveBtn.addEventListener('click', function() { self.handleApprove(); });
            if (skipBtn) skipBtn.addEventListener('click', function() { self.handleSkip(); });
            if (rejectBtn) rejectBtn.addEventListener('click', function() { self.handleReject(); });
            if (prevBtn) prevBtn.addEventListener('click', function() { self.navigatePrev(); });
            if (nextBtn) nextBtn.addEventListener('click', function() { self.navigateNext(); });

            // Zoom controls
            var zoomIn = document.getElementById('lens-zoom-in');
            var zoomOut = document.getElementById('lens-zoom-out');
            var zoomFit = document.getElementById('lens-zoom-fit');

            if (zoomIn) zoomIn.addEventListener('click', function() { self.handleZoom(1); });
            if (zoomOut) zoomOut.addEventListener('click', function() { self.handleZoom(-1); });
            if (zoomFit) zoomFit.addEventListener('click', function() { self.zoomLevel = 3; self.applyZoom(); });

            // Focal point click
            var imgContainer = document.getElementById('lens-image-container');
            if (imgContainer) {
                imgContainer.addEventListener('click', function(e) { self.handleFocalPointClick(e); });
            }

            // Mode toggle
            var modeToggle = document.getElementById('lens-mode-toggle');
            if (modeToggle) {
                modeToggle.addEventListener('click', function(e) {
                    var btn = e.target.closest('[data-mode]');
                    if (btn) self.switchMode(btn.dataset.mode);
                });
            }

            // Grid buttons
            var gridSelectAll = document.getElementById('lens-grid-select-all');
            var gridSelectHigh = document.getElementById('lens-grid-select-high');
            var gridApprove = document.getElementById('lens-grid-approve');
            var gridReject = document.getElementById('lens-grid-reject');

            if (gridSelectAll) gridSelectAll.addEventListener('click', function() { self.handleGridSelectAll(); });
            if (gridSelectHigh) gridSelectHigh.addEventListener('click', function() { self.handleGridSelectHigh(); });
            if (gridApprove) gridApprove.addEventListener('click', function() { self.handleGridApprove(); });
            if (gridReject) gridReject.addEventListener('click', function() { self.handleGridReject(); });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) { self.handleKeydown(e); });

            // Listen for people detection updates to sync currentData
            document.addEventListener('lens:peopleDetectionUpdated', function(e) {
                if (self.currentData && self.currentData.id == e.detail.analysisId) {
                    self.currentData.containsPeople = e.detail.containsPeople;
                    self.currentData.faceCount = e.detail.faceCount;
                }
            });
        },

        // ==================================================================
        // Keyboard Shortcuts
        // ==================================================================

        handleKeydown: function(e) {
            var isInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName);

            if (e.key === 'Escape') {
                if (document.activeElement) document.activeElement.blur();
                return;
            }

            if (isInput) return;

            // A/S/R/arrows only work in focus (single) mode
            if (this.mode !== 'single') return;

            switch (e.key.toLowerCase()) {
                case 'a':
                    e.preventDefault();
                    this.handleApprove();
                    break;
                case 's':
                    e.preventDefault();
                    this.handleSkip();
                    break;
                case 'r':
                    e.preventDefault();
                    this.handleReject();
                    break;
                case 'arrowleft':
                    e.preventDefault();
                    this.navigatePrev();
                    break;
                case 'arrowright':
                    e.preventDefault();
                    this.navigateNext();
                    break;
            }
        },

        // ==================================================================
        // AJAX Loading
        // ==================================================================

        loadAnalysis: function(id) {
            var self = this;
            if (this.isLoading) return;
            this.isLoading = true;

            this.showLoading(true);

            Craft.sendActionRequest('GET', 'lens/review/get-analysis', {
                params: { analysisId: id }
            }).then(function(response) {
                if (response.data.success) {
                    self.currentData = response.data.data;
                    // Update queue IDs from server (another user may have acted)
                    if (response.data.data.queue && response.data.data.queue.ids) {
                        self.queueIds = response.data.data.queue.ids;
                    }
                    self.currentIndex = self.queueIds.indexOf(id);
                    if (self.currentIndex === -1) self.currentIndex = 0;
                    self.renderSingleReview();
                } else {
                    // Not found or already reviewed - skip to next
                    self.removeFromQueue(id);
                    Craft.cp.displayNotice(response.data.message || Craft.t('lens', 'Item already reviewed, moving to next.'));
                    self.advanceAfterAction();
                }
            }).catch(function(error) {
                console.error('[Lens] Error loading analysis:', error);
                Craft.cp.displayError(Craft.t('lens', 'Failed to load analysis.'));
            }).finally(function() {
                self.isLoading = false;
                self.showLoading(false);
            });
        },

        showLoading: function(show) {
            var loading = document.getElementById('lens-review-loading');
            var panels = document.getElementById('lens-review-panels');
            var actionBar = document.getElementById('lens-action-bar');

            if (loading) loading.style.display = show ? 'flex' : 'none';
            if (panels) panels.style.display = show ? 'none' : '';
            if (actionBar) actionBar.style.display = show ? 'none' : '';
        },

        // ==================================================================
        // Rendering
        // ==================================================================

        renderSingleReview: function() {
            if (!this.currentData) return;

            var d = this.currentData;

            // Reset state
            this.focalPointChanged = false;
            this.focalX = d.focalPointX;
            this.focalY = d.focalPointY;
            this.zoomLevel = 3;

            // Image
            var img = document.getElementById('lens-review-image');
            if (img) {
                img.src = d.thumbnailUrl || '';
                img.alt = d.altText || d.filename;
                img.style.transform = '';
            }

            // Zoom display
            this.applyZoom();

            // Focal marker
            this.updateFocalMarker();

            // Asset header
            this.renderAssetHeader(d);

            // Content section
            this.renderContentSection(d);

            // Detection section
            this.renderDetectionSection(d);

            // Metadata section
            this.renderMetadataSection(d);

            // Similar images
            this.renderSimilarImages(d.similarImages || []);

            // Navigation
            this.updateNavigation();

            // Show panels
            var panels = document.getElementById('lens-review-panels');
            var actionBar = document.getElementById('lens-action-bar');
            if (panels) panels.style.display = '';
            if (actionBar) actionBar.style.display = '';
        },

        renderAssetHeader: function(d) {
            var el = document.getElementById('lens-asset-header');
            if (!el) return;

            var sizeStr = d.fileSize ? this.formatFileSize(d.fileSize) : '';
            var metaParts = [sizeStr, d.uploadDate].filter(Boolean);

            el.innerHTML = '<h3><a href="' + this.escapeHtml(d.editUrl || '#') + '" target="_blank">' +
                this.escapeHtml(d.filename) + '</a></h3>' +
                '<div class="lens-review-asset-meta">' + this.escapeHtml(metaParts.join(' &bull; ')) + '</div>';
        },

        renderContentSection: function(d) {
            var el = document.getElementById('lens-content-fields');
            if (!el) return;

            var html = '';

            // Suggested Title
            if (d.suggestedTitle !== null) {
                html += this.renderEditableField('suggestedTitle', 'Suggested Title', d.suggestedTitle, 'text', d.titleConfidence);
            }

            // Alt Text
            if (d.altText !== null) {
                html += this.renderEditableField('altText', 'Alt Text', d.altText, 'textarea', d.altTextConfidence);
            }

            // Long Description
            if (d.longDescription !== null) {
                html += this.renderEditableField('longDescription', 'Description', d.longDescription, 'textarea-long', d.longDescriptionConfidence);
            }

            // Tags
            html += '<div class="lens-review-field">';
            html += '<div class="lens-field-label">' + Craft.t('lens', 'Tags') + '</div>';
            html += '<div class="lens-tag-editor" id="lens-review-tag-editor" data-field="tags">';
            if (d.tags && d.tags.length) {
                d.tags.forEach(function(tag) {
                    html += '<span class="lens-tag lens-tag--editable ' + (tag.isAi ? '' : 'lens-tag--user') + '"' +
                        ' data-tag="' + this.escapeAttr(tag.tag) + '"' +
                        ' data-confidence="' + (tag.confidence || 1) + '"' +
                        ' data-is-ai="' + (tag.isAi ? '1' : '0') + '">' +
                        this.escapeHtml(tag.tag) +
                        ' <button type="button" class="lens-tag-remove" aria-label="' + Craft.t('lens', 'Remove') + '">&times;</button>' +
                        '</span>';
                }.bind(this));
            }
            html += '<input type="text" class="lens-tag-input text" placeholder="' + Craft.t('lens', 'Add tag...') + '">';
            html += '</div>';
            html += '</div>';

            // Colors
            html += '<div class="lens-review-field">';
            html += '<div class="lens-field-label">' + Craft.t('lens', 'Dominant Colors') + '</div>';
            html += '<div class="lens-color-editor" id="lens-review-color-editor" data-field="dominantColors">';
            if (d.colors && d.colors.length) {
                d.colors.forEach(function(color) {
                    html += '<div class="lens-color-item ' + (color.isAi ? '' : 'lens-color--user') + '"' +
                        ' data-hex="' + this.escapeAttr(color.hex) + '"' +
                        ' data-percentage="' + (color.percentage || 0) + '"' +
                        ' data-is-ai="' + (color.isAi ? '1' : '0') + '">' +
                        '<input type="color" class="lens-color-picker" value="' + this.escapeAttr(color.hex) + '">' +
                        '<span class="lens-color-hex">' + this.escapeHtml(color.hex) + '</span>' +
                        (color.percentage ? '<span class="lens-color-pct">' + Math.round(color.percentage * 100) + '%</span>' : '') +
                        '<button type="button" class="lens-color-remove" aria-label="' + Craft.t('lens', 'Remove') + '">&times;</button>' +
                        '</div>';
                }.bind(this));
            }
            html += '<button type="button" class="btn small lens-color-add">' + Craft.t('lens', 'Add Color') + '</button>';
            html += '</div>';
            html += '</div>';

            // Extracted text (if available)
            if (d.extractedText) {
                html += this.renderEditableField('extractedText', 'Extracted Text', d.extractedText, 'textarea', null);
            }

            // Focal point indicator
            if (d.focalPointX !== null && d.focalPointY !== null) {
                html += '<div class="lens-review-field">';
                html += '<div class="lens-field-label">' + Craft.t('lens', 'Focal Point') +
                    (d.focalPointConfidence !== null && d.focalPointConfidence < this.HIGH_CONFIDENCE_THRESHOLD
                        ? ' ' + this.renderConfidenceBadge(d.focalPointConfidence) : '') +
                    '</div>';
                html += '<div class="lens-review-asset-meta" id="lens-focal-display">' +
                    Craft.t('lens', 'Click on the image to set focal point. Current: {x}, {y}', {
                        x: Math.round(d.focalPointX * 100) + '%',
                        y: Math.round(d.focalPointY * 100) + '%'
                    }) + '</div>';
                html += '</div>';
            }

            // People Detection (editable in review)
            html += this.renderPeopleDetectionField(d);

            el.innerHTML = html;

            // Re-init tag, color, and people editors for this new content
            this.initReviewTagEditor();
            this.initReviewColorEditor();
            this.initReviewPeopleEditor();
        },

        renderEditableField: function(name, label, value, type, confidence) {
            var html = '<div class="lens-review-field">';
            html += '<div class="lens-field-label">' + Craft.t('lens', label);
            if (confidence !== null && confidence !== undefined && confidence < this.HIGH_CONFIDENCE_THRESHOLD) {
                html += ' ' + this.renderConfidenceBadge(confidence);
            }
            html += '</div>';

            if (type === 'text') {
                html += '<input type="text" class="text fullwidth lens-review-editable" data-field="' + name + '" value="' + this.escapeAttr(value || '') + '">';
            } else if (type === 'textarea') {
                html += '<textarea class="text fullwidth lens-review-editable" data-field="' + name + '" rows="2">' + this.escapeHtml(value || '') + '</textarea>';
            } else if (type === 'textarea-long') {
                var escapedVal = this.escapeHtml(value || '');
                html += '<div class="lens-review-desc-preview" id="lens-desc-preview">';
                html += '<textarea class="text fullwidth lens-review-editable" data-field="' + name + '" rows="3">' + escapedVal + '</textarea>';
                html += '</div>';
                if (value && value.length > 200) {
                    html += '<button type="button" class="lens-review-desc-toggle" onclick="var p=document.getElementById(\'lens-desc-preview\');p.classList.toggle(\'expanded\');this.textContent=p.classList.contains(\'expanded\')?\'' + Craft.t('lens', 'Show less') + '\':\'' + Craft.t('lens', 'Show more') + '\';">' + Craft.t('lens', 'Show more') + '</button>';
                }
            }

            html += '</div>';
            return html;
        },

        renderConfidenceBadge: function(confidence) {
            return Lens.utils.renderConfidenceBadge(confidence);
        },

        renderPeopleDetectionField: function(d) {
            var containsPeople = d.containsPeople || false;
            var faceCount = d.faceCount || 0;
            var containsPeopleAi = d.containsPeopleAi || false;
            var faceCountAi = d.faceCountAi || 0;
            var analysisId = d.analysisId || d.id || '';

            // In review mode: simplified version - just radio buttons, no display/edit toggle
            var html = '<div class="lens-review-field">';
            html += '<div class="lens-field-label">' + Craft.t('lens', 'People Detection') + '</div>';
            html += '<div class="lens-people-detection lens-editable-field"' +
                ' data-analysis-id="' + analysisId + '"' +
                ' data-contains-people="' + (containsPeople ? '1' : '0') + '"' +
                ' data-face-count="' + faceCount + '"' +
                ' data-contains-people-ai="' + (containsPeopleAi ? '1' : '0') + '"' +
                ' data-face-count-ai="' + faceCountAi + '"' +
                ' data-context="review">';

            // Radio buttons - always visible in review mode
            html += '<div class="lens-people-edit-options">';

            html += '<label class="lens-people-option">';
            html += '<input type="radio" name="people-mode-' + analysisId + '" value="none" data-control="people-mode-review"';
            if (!containsPeople) html += ' checked';
            html += '>';
            html += '<span>' + Craft.t('lens', 'No people present') + '</span>';
            html += '</label>';

            html += '<label class="lens-people-option">';
            html += '<input type="radio" name="people-mode-' + analysisId + '" value="no-faces" data-control="people-mode-review"';
            if (containsPeople && faceCount === 0) html += ' checked';
            html += '>';
            html += '<span>' + Craft.t('lens', 'People present, no visible faces') + '</span>';
            html += '</label>';

            html += '<label class="lens-people-option">';
            html += '<input type="radio" name="people-mode-' + analysisId + '" value="1" data-control="people-mode-review"';
            if (containsPeople && faceCount === 1) html += ' checked';
            html += '>';
            html += '<span>' + Craft.t('lens', 'Individual (1 person)') + '</span>';
            html += '</label>';

            html += '<label class="lens-people-option">';
            html += '<input type="radio" name="people-mode-' + analysisId + '" value="2" data-control="people-mode-review"';
            if (containsPeople && faceCount === 2) html += ' checked';
            html += '>';
            html += '<span>' + Craft.t('lens', 'Duo (2 people)') + '</span>';
            html += '</label>';

            html += '<label class="lens-people-option">';
            html += '<input type="radio" name="people-mode-' + analysisId + '" value="3-5" data-control="people-mode-review"';
            if (containsPeople && faceCount >= 3 && faceCount <= 5) html += ' checked';
            html += '>';
            html += '<span>' + Craft.t('lens', 'Small group (3-5 people)') + '</span>';
            html += '</label>';

            html += '<label class="lens-people-option">';
            html += '<input type="radio" name="people-mode-' + analysisId + '" value="6+" data-control="people-mode-review"';
            if (containsPeople && faceCount >= 6) html += ' checked';
            html += '>';
            html += '<span>' + Craft.t('lens', 'Large group (6+ people)') + '</span>';
            html += '</label>';

            html += '</div>'; // end lens-people-edit-options

            // AI suggestion - always show in review mode
            html += '<div class="lens-ai-suggestion" style="margin-top: 12px;">';
            var aiSuggestionText = Lens.utils.formatPeopleDetectionText(containsPeopleAi, faceCountAi);
            html += Craft.t('lens', 'AI suggested: "{text}"', {text: aiSuggestionText});
            html += '</div>'; // end lens-ai-suggestion

            html += '</div>'; // end lens-people-detection
            html += '</div>'; // end lens-review-field
            return html;
        },

        renderDetectionSection: function(d) {
            var el = document.getElementById('lens-detection-fields');
            if (!el) return;

            var html = '';
            var hasContent = false;

            // NSFW
            if (d.isFlaggedNsfw) {
                hasContent = true;
                html += '<div class="lens-detection-item" style="background: var(--bg-error, #fef2f2);">';
                html += '<span class="lens-detection-label"><span class="lens-badge lens-badge--error-solid">NSFW</span> ' +
                    Craft.t('lens', 'Score: {score}%', {score: Math.round((d.nsfwScore || 0) * 100)}) + '</span>';
                html += '<button type="button" class="lens-detection-action" data-clear-field="nsfw">' + Craft.t('lens', 'Clear Flag') + '</button>';
                html += '</div>';
            }

            // Watermark
            if (d.hasWatermark) {
                hasContent = true;
                html += '<div class="lens-detection-item" style="background: var(--bg-warning, #fffbeb);">';
                html += '<span class="lens-detection-label">' + Craft.t('lens', 'Watermark Detected') +
                    (d.watermarkType ? ' (' + this.escapeHtml(d.watermarkType) + ')' : '') + '</span>';
                html += '<button type="button" class="lens-detection-action" data-clear-field="watermark">' + Craft.t('lens', 'Clear Flag') + '</button>';
                html += '</div>';
            }

            // Brand logos
            if (d.containsBrandLogo) {
                hasContent = true;
                html += '<div class="lens-detection-item">';
                html += '<span class="lens-detection-label">' + Craft.t('lens', 'Brand Logo Detected');
                if (d.detectedBrands && d.detectedBrands.length) {
                    html += ' (' + d.detectedBrands.map(function(b) { return this.escapeHtml(typeof b === 'string' ? b : (b.name || '')); }.bind(this)).join(', ') + ')';
                }
                html += '</span>';
                html += '<button type="button" class="lens-detection-action" data-clear-field="brand">' + Craft.t('lens', 'Clear Flag') + '</button>';
                html += '</div>';
            }

            // Quality scores
            if (d.overallQualityScore !== null) {
                hasContent = true;
                var qualityLabel = d.overallQualityScore >= 0.7 ? 'Good' : (d.overallQualityScore >= 0.4 ? 'Fair' : 'Poor');
                var qualityCls = d.overallQualityScore >= 0.7 ? 'lens-badge--success' : (d.overallQualityScore >= 0.4 ? 'lens-badge--warning' : 'lens-badge--error');

                html += '<div class="lens-detection-item">';
                html += '<span class="lens-detection-label">' + Craft.t('lens', 'Image Quality') + '</span>';
                html += '<span class="lens-badge ' + qualityCls + '">' + Craft.t('lens', qualityLabel) +
                    ' (' + Math.round(d.overallQualityScore * 100) + '%)</span>';
                html += '</div>';

                // Individual metrics
                if (d.sharpnessScore !== null || d.exposureScore !== null || d.noiseScore !== null) {
                    html += '<div class="lens-quality-metrics">';
                    if (d.sharpnessScore !== null) {
                        html += this.renderMetricBar('Sharpness', d.sharpnessScore);
                    }
                    if (d.exposureScore !== null) {
                        html += this.renderMetricBar('Exposure', d.exposureScore);
                    }
                    if (d.noiseScore !== null) {
                        html += this.renderMetricBar('Noise', d.noiseScore);
                    }
                    html += '</div>';
                }
            }

            if (!hasContent) {
                html = '<div class="lens-review-asset-meta">' + Craft.t('lens', 'No detection flags for this image.') + '</div>';
            }

            el.innerHTML = html;

            // Bind clear flag actions
            el.querySelectorAll('[data-clear-field]').forEach(function(btn) {
                btn.addEventListener('click', this.handleClearFlag.bind(this));
            }.bind(this));
        },

        renderMetricBar: function(label, value) {
            return '<div class="lens-quality-metric">' +
                '<span class="lens-metric-label">' + Craft.t('lens', label) + '</span>' +
                '<div class="lens-metric-bar"><div class="lens-metric-fill" style="width:' + Math.round(value * 100) + '%"></div></div>' +
                '</div>';
        },

        renderMetadataSection: function(d) {
            var el = document.getElementById('lens-metadata-fields');
            if (!el) return;

            var html = '';
            var hasContent = false;

            // EXIF
            if (d.exif) {
                hasContent = true;
                html += '<table class="data fullwidth">';

                if (d.exif.cameraDisplay) {
                    html += '<tr><th>' + Craft.t('lens', 'Camera') + '</th><td>' + this.escapeHtml(d.exif.cameraDisplay) + '</td></tr>';
                }
                if (d.exif.lens) {
                    html += '<tr><th>' + Craft.t('lens', 'Lens') + '</th><td>' + this.escapeHtml(d.exif.lens) + '</td></tr>';
                }
                if (d.exif.dateTaken) {
                    html += '<tr><th>' + Craft.t('lens', 'Date Taken') + '</th><td>' + this.escapeHtml(d.exif.dateTaken) + '</td></tr>';
                }
                if (d.exif.width && d.exif.height) {
                    html += '<tr><th>' + Craft.t('lens', 'Dimensions') + '</th><td>' + d.exif.width + ' &times; ' + d.exif.height + '</td></tr>';
                }
                if (d.exif.gpsDisplay) {
                    html += '<tr><th>' + Craft.t('lens', 'GPS') + '</th><td>' + this.escapeHtml(d.exif.gpsDisplay) +
                        ' <a href="https://www.openstreetmap.org/?mlat=' + d.exif.latitude + '&mlon=' + d.exif.longitude +
                        '#map=15/' + d.exif.latitude + '/' + d.exif.longitude + '" target="_blank" rel="noopener">' +
                        Craft.t('lens', 'View Map') + '</a></td></tr>';
                }

                html += '</table>';
            }

            if (!hasContent) {
                html = '<div class="lens-review-asset-meta">' + Craft.t('lens', 'No metadata available.') + '</div>';
            }

            el.innerHTML = html;
        },

        renderSimilarImages: function(images) {
            var section = document.getElementById('lens-similar-section');
            var list = document.getElementById('lens-similar-list');
            if (!section || !list) return;

            if (!images || images.length === 0) {
                section.style.display = 'none';
                return;
            }

            section.style.display = '';
            var html = '';

            images.forEach(function(img) {
                html += '<a href="' + this.escapeAttr(img.editUrl || '#') + '" class="lens-review-similar-item" target="_blank">' +
                    '<img src="' + this.escapeAttr(img.thumbnailUrl) + '" alt="' + this.escapeAttr(img.filename) + '">' +
                    '<div class="lens-review-similar-score">' + Math.round((img.similarity || 0) * 100) + '%</div>' +
                    '</a>';
            }.bind(this));

            list.innerHTML = html;
        },

        updateNavigation: function() {
            var counter = document.getElementById('lens-review-counter');
            var prevBtn = document.getElementById('lens-prev-btn');
            var nextBtn = document.getElementById('lens-next-btn');

            var total = this.queueIds.length;
            var pos = this.currentIndex + 1;

            if (counter) {
                counter.textContent = pos + ' of ' + total;
            }
            if (prevBtn) prevBtn.disabled = this.currentIndex <= 0;
            if (nextBtn) nextBtn.disabled = this.currentIndex >= total - 1;
        },

        // ==================================================================
        // Tag & Color Editors (Re-init for AJAX-loaded content)
        // ==================================================================

        initReviewTagEditor: function() {
            var editor = document.getElementById('lens-review-tag-editor');
            if (!editor) return;
            var tagInput = editor.querySelector('.lens-tag-input');
            if (!tagInput) return;

            // Remove tag
            editor.addEventListener('click', function(e) {
                var removeBtn = e.target.closest('.lens-tag-remove');
                if (removeBtn) {
                    removeBtn.closest('.lens-tag').remove();
                }
            });

            // Add tag on Enter
            tagInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    var value = tagInput.value.trim().toLowerCase();
                    if (!value) return;

                    // Check for duplicates
                    var existing = editor.querySelectorAll('.lens-tag');
                    for (var i = 0; i < existing.length; i++) {
                        if (existing[i].dataset.tag === value) return;
                    }

                    var span = document.createElement('span');
                    span.className = 'lens-tag lens-tag--editable lens-tag--user';
                    span.dataset.tag = value;
                    span.dataset.confidence = '1.0';
                    span.dataset.isAi = '0';
                    span.innerHTML = value + ' <button type="button" class="lens-tag-remove" aria-label="Remove">&times;</button>';

                    editor.insertBefore(span, tagInput);
                    tagInput.value = '';
                }
            });
        },

        initReviewColorEditor: function() {
            var editor = document.getElementById('lens-review-color-editor');
            if (!editor) {
                console.warn('[Lens] Color editor element #lens-review-color-editor not found');
                return;
            }

            // Color picker change
            editor.addEventListener('input', function(e) {
                if (e.target.classList.contains('lens-color-picker')) {
                    var item = e.target.closest('.lens-color-item');
                    var hex = e.target.value.toUpperCase();
                    item.dataset.hex = hex;
                    item.dataset.isAi = '0';
                    item.classList.add('lens-color--user');
                    item.querySelector('.lens-color-hex').textContent = hex;
                }
            });

            editor.addEventListener('click', function(e) {
                // Remove color
                var removeBtn = e.target.closest('.lens-color-remove');
                if (removeBtn) {
                    removeBtn.closest('.lens-color-item').remove();
                }

                // Add color
                var addBtn = e.target.closest('.lens-color-add');
                if (addBtn) {
                    var div = document.createElement('div');
                    div.className = 'lens-color-item lens-color--user';
                    div.dataset.hex = '#000000';
                    div.dataset.percentage = '0';
                    div.dataset.isAi = '0';
                    div.innerHTML = '<input type="color" class="lens-color-picker" value="#000000">' +
                        '<span class="lens-color-hex">#000000</span>' +
                        '<button type="button" class="lens-color-remove" aria-label="Remove">&times;</button>';
                    editor.insertBefore(div, addBtn);
                }
            });
        },

        initReviewPeopleEditor: function() {
            var editor = document.querySelector('.lens-people-detection[data-context="review"]');
            if (!editor) return;

            // Listen for radio button changes
            editor.addEventListener('change', function(e) {
                if (e.target.matches('[data-control="people-mode-review"]')) {
                    var value = e.target.value;
                    var containsPeople, faceCount;

                    // Map radio value to field values
                    switch (value) {
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
                            faceCount = 4; // middle of range
                            break;
                        case '6+':
                            containsPeople = true;
                            faceCount = 6;
                            break;
                    }

                    // Update data attributes for currentData sync
                    editor.dataset.containsPeople = containsPeople ? '1' : '0';
                    editor.dataset.faceCount = faceCount;
                }
            });
        },

        // ==================================================================
        // Collect Modifications
        // ==================================================================

        collectModifications: function() {
            var mods = {};

            // Text fields
            document.querySelectorAll('.lens-review-editable').forEach(function(input) {
                var field = input.dataset.field;
                if (field) {
                    mods[field] = input.value;
                }
            });

            // Tags
            var tagEditor = document.getElementById('lens-review-tag-editor');
            if (tagEditor) {
                var tags = [];
                tagEditor.querySelectorAll('.lens-tag').forEach(function(tagEl) {
                    tags.push({
                        tag: tagEl.dataset.tag,
                        confidence: parseFloat(tagEl.dataset.confidence) || 1.0,
                        isAi: tagEl.dataset.isAi === '1'
                    });
                });
                mods.tags = JSON.stringify(tags);
            }

            // Colors
            var colorEditor = document.getElementById('lens-review-color-editor');
            if (colorEditor) {
                var colors = [];
                colorEditor.querySelectorAll('.lens-color-item').forEach(function(item) {
                    colors.push({
                        hex: item.dataset.hex,
                        percentage: parseFloat(item.dataset.percentage) || 0,
                        isAi: item.dataset.isAi === '1'
                    });
                });
                mods.dominantColors = JSON.stringify(colors);
            }

            // Focal point
            if (this.focalPointChanged && this.focalX !== null && this.focalY !== null) {
                mods.focalPointX = this.focalX;
                mods.focalPointY = this.focalY;
            }

            // People detection
            var peopleEditor = document.querySelector('.lens-people-detection[data-context="review"]');
            if (peopleEditor) {
                var containsPeople = peopleEditor.dataset.containsPeople === '1';
                var faceCount = parseInt(peopleEditor.dataset.faceCount) || 0;

                // Only include if changed from original values
                if (containsPeople !== (this.currentData.containsPeople || false) ||
                    faceCount !== (this.currentData.faceCount || 0)) {
                    mods.containsPeople = containsPeople;
                    mods.faceCount = faceCount;
                }
            }

            return mods;
        },

        // ==================================================================
        // Actions
        // ==================================================================

        handleApprove: function() {
            if (this.isLoading || !this.currentData) return;
            var self = this;
            var analysisId = this.currentData.analysisId;
            this.setActionsDisabled(true);

            var mods = this.collectModifications();
            mods.analysisId = analysisId;

            Craft.sendActionRequest('POST', 'lens/review/approve', {
                data: mods
            }).then(function() {
                self.removeFromQueue(analysisId);
                Craft.cp.displayNotice(Craft.t('lens', 'Analysis approved.'));
                self.advanceAfterAction();
            }).catch(function(error) {
                console.error('[Lens] Approve error:', error);
                Craft.cp.displayError(Craft.t('lens', 'Failed to approve.'));
                self.setActionsDisabled(false);
            });
        },

        handleSkip: function() {
            if (this.isLoading || !this.currentData) return;
            var self = this;
            var analysisId = this.currentData.analysisId;
            this.setActionsDisabled(true);

            Craft.sendActionRequest('POST', 'lens/review/skip', {
                data: { analysisId: analysisId }
            }).then(function() {
                // Move to end of queue
                self.moveToEndOfQueue(analysisId);
                Craft.cp.displayNotice(Craft.t('lens', 'Skipped.'));
                self.advanceAfterAction();
            }).catch(function(error) {
                console.error('[Lens] Skip error:', error);
                Craft.cp.displayError(Craft.t('lens', 'Failed to skip.'));
                self.setActionsDisabled(false);
            });
        },

        handleReject: function() {
            if (this.isLoading || !this.currentData) return;
            var self = this;
            var analysisId = this.currentData.analysisId;
            this.setActionsDisabled(true);

            Craft.sendActionRequest('POST', 'lens/review/reject', {
                data: { analysisId: analysisId }
            }).then(function() {
                self.removeFromQueue(analysisId);
                Craft.cp.displayNotice(Craft.t('lens', 'Analysis rejected.'));
                self.advanceAfterAction();
            }).catch(function(error) {
                console.error('[Lens] Reject error:', error);
                Craft.cp.displayError(Craft.t('lens', 'Failed to reject.'));
                self.setActionsDisabled(false);
            });
        },

        handleClearFlag: function(e) {
            var field = e.currentTarget.dataset.clearField;
            if (!field || !this.currentData) return;

            switch (field) {
                case 'nsfw':
                    this.currentData.nsfwScore = 0;
                    this.currentData.isFlaggedNsfw = false;
                    break;
                case 'watermark':
                    this.currentData.hasWatermark = false;
                    break;
                case 'brand':
                    this.currentData.containsBrandLogo = false;
                    break;
            }

            this.renderDetectionSection(this.currentData);
        },

        // ==================================================================
        // Focal Point
        // ==================================================================

        handleFocalPointClick: function(e) {
            var container = document.getElementById('lens-image-container');
            var img = document.getElementById('lens-review-image');
            if (!container || !img) return;

            var rect = img.getBoundingClientRect();
            var x = (e.clientX - rect.left) / rect.width;
            var y = (e.clientY - rect.top) / rect.height;

            x = Math.max(0, Math.min(1, x));
            y = Math.max(0, Math.min(1, y));

            this.focalX = parseFloat(x.toFixed(4));
            this.focalY = parseFloat(y.toFixed(4));
            this.focalPointChanged = true;

            this.updateFocalMarker();

            // Update display text
            var display = document.getElementById('lens-focal-display');
            if (display) {
                display.textContent = Craft.t('lens', 'Click on the image to set focal point. Current: {x}, {y}', {
                    x: Math.round(this.focalX * 100) + '%',
                    y: Math.round(this.focalY * 100) + '%'
                });
            }
        },

        updateFocalMarker: function() {
            var marker = document.getElementById('lens-focal-marker');
            if (!marker) return;

            if (this.focalX !== null && this.focalY !== null) {
                marker.style.display = '';
                marker.style.left = (this.focalX * 100) + '%';
                marker.style.top = (this.focalY * 100) + '%';
            } else {
                marker.style.display = 'none';
            }
        },

        // ==================================================================
        // Zoom
        // ==================================================================

        handleZoom: function(dir) {
            this.zoomLevel = Math.max(0, Math.min(this.ZOOM_STEPS.length - 1, this.zoomLevel + dir));
            this.applyZoom();
        },

        applyZoom: function() {
            var img = document.getElementById('lens-review-image');
            var display = document.getElementById('lens-zoom-level');
            var scale = this.ZOOM_STEPS[this.zoomLevel];

            if (img) {
                img.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';
            }
            if (display) {
                display.textContent = Math.round(scale * 100) + '%';
            }
        },

        // ==================================================================
        // Navigation
        // ==================================================================

        navigatePrev: function() {
            if (this.currentIndex > 0) {
                this.currentIndex--;
                this.loadAnalysis(this.queueIds[this.currentIndex]);
            }
        },

        navigateNext: function() {
            if (this.currentIndex < this.queueIds.length - 1) {
                this.currentIndex++;
                this.loadAnalysis(this.queueIds[this.currentIndex]);
            }
        },

        removeFromQueue: function(id) {
            var idx = this.queueIds.indexOf(id);
            if (idx !== -1) {
                this.queueIds.splice(idx, 1);
                if (this.currentIndex >= this.queueIds.length) {
                    this.currentIndex = Math.max(0, this.queueIds.length - 1);
                }
            }
        },

        moveToEndOfQueue: function(id) {
            var idx = this.queueIds.indexOf(id);
            if (idx !== -1) {
                this.queueIds.splice(idx, 1);
                this.queueIds.push(id);
                // Keep currentIndex pointing to the same position (next item)
                if (this.currentIndex >= this.queueIds.length - 1) {
                    this.currentIndex = 0;
                }
            }
        },

        advanceAfterAction: function() {
            this.setActionsDisabled(false);

            if (this.queueIds.length === 0) {
                this.showEmptyState();
                return;
            }

            // Clamp index
            if (this.currentIndex >= this.queueIds.length) {
                this.currentIndex = 0;
            }

            this.loadAnalysis(this.queueIds[this.currentIndex]);
        },

        showEmptyState: function() {
            var browseMode = document.getElementById('lens-browse-mode');
            var singleMode = document.getElementById('lens-single-mode');
            var gridMode = document.getElementById('lens-grid-mode');
            var toolbar = document.querySelector('.lens-review-toolbar');

            if (browseMode) browseMode.innerHTML = '';
            if (singleMode) singleMode.innerHTML = '';
            if (gridMode) gridMode.innerHTML = '';
            if (toolbar) toolbar.style.display = 'none';

            var container = singleMode || document.querySelector('.lens-review-queue') || document.getElementById('content');
            if (container) {
                container.innerHTML = '<div class="lens-empty-state">' +
                    '<div class="lens-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg></div>' +
                    '<h3 class="lens-empty-heading">' + Craft.t('lens', 'All Done!') + '</h3>' +
                    '<p class="lens-empty-description">' + Craft.t('lens', 'All AI suggestions have been reviewed.') + '</p>' +
                    '<div class="lens-empty-actions">' +
                    '<a href="' + Craft.getCpUrl('lens/bulk') + '" class="btn submit">' + Craft.t('lens', 'Process Assets') + '</a>' +
                    '<a href="' + Craft.getCpUrl('lens/dashboard') + '" class="btn">' + Craft.t('lens', 'Go to Dashboard') + '</a>' +
                    '</div></div>';
            }
        },

        setActionsDisabled: function(disabled) {
            ['lens-approve-btn', 'lens-skip-btn', 'lens-reject-btn', 'lens-prev-btn', 'lens-next-btn'].forEach(function(id) {
                var btn = document.getElementById(id);
                if (btn) btn.disabled = disabled;
            });
        },

        // ==================================================================
        // Mode Switching
        // ==================================================================

        switchMode: function(mode) {
            this.mode = mode;

            var browseMode = document.getElementById('lens-browse-mode');
            var singleMode = document.getElementById('lens-single-mode');
            var gridMode = document.getElementById('lens-grid-mode');
            var kbdHints = document.getElementById('lens-kbd-hints');
            var toggleBtns = document.querySelectorAll('#lens-mode-toggle [data-mode]');

            toggleBtns.forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.mode === mode);
            });

            // Hide all modes
            if (browseMode) browseMode.classList.remove('active');
            if (singleMode) singleMode.classList.add('hidden');
            if (gridMode) gridMode.classList.remove('active');

            // Show/hide keyboard hints (only in focus view)
            if (kbdHints) kbdHints.style.display = mode === 'single' ? '' : 'none';

            if (mode === 'browse') {
                if (browseMode) browseMode.classList.add('active');
                this.loadBrowseView(this.browsePage);
            } else if (mode === 'single') {
                if (singleMode) singleMode.classList.remove('hidden');
                if (!this.currentData) {
                    // Load first queue item if nothing loaded yet
                    if (this.queueIds.length > 0) {
                        this.loadAnalysis(this.queueIds[this.currentIndex]);
                    }
                }
            } else if (mode === 'grid') {
                if (gridMode) gridMode.classList.add('active');
                this.loadGridView();
            }
        },

        // ==================================================================
        // Browse View
        // ==================================================================

        loadBrowseView: function(page) {
            var self = this;
            page = page || 1;
            this.browsePage = page;

            var container = document.getElementById('lens-browse-container');
            var pagination = document.getElementById('lens-browse-pagination');

            if (container) container.innerHTML = '<div class="lens-review-loading"><span class="visually-hidden">Loading...</span></div>';

            Craft.sendActionRequest('GET', 'lens/review/get-queue', {
                params: { page: page, perPage: this.browsePerPage }
            }).then(function(response) {
                if (response.data.success) {
                    self.browseTotalPages = response.data.totalPages || 1;
                    self.renderBrowseView(response.data.items);
                    self.renderBrowsePagination(self.browsePage, self.browseTotalPages);

                    // Update counter
                    var counter = document.getElementById('lens-review-counter');
                    if (counter) {
                        counter.textContent = response.data.totalCount + ' ' + Craft.t('lens', 'pending');
                    }
                }
            }).catch(function(error) {
                console.error('[Lens] Browse load error:', error);
                Craft.cp.displayError(Craft.t('lens', 'Failed to load browse view.'));
            });
        },

        renderBrowseView: function(items) {
            var self = this;
            var container = document.getElementById('lens-browse-container');
            if (!container) return;

            if (!items || items.length === 0) {
                container.innerHTML = '<div class="lens-review-asset-meta" style="grid-column: 1/-1; text-align: center; padding: 40px;">' +
                    Craft.t('lens', 'No items in queue.') + '</div>';
                return;
            }

            var html = '';
            items.forEach(function(item) {
                var confPct = Math.round((item.avgConfidence || 0) * 100);
                var confCls = item.avgConfidence >= 0.8 ? 'lens-badge--success' :
                    (item.avgConfidence >= 0.5 ? 'lens-badge--warning' : 'lens-badge--error');

                html += '<div class="lens-browse-card" data-analysis-id="' + item.analysisId + '" tabindex="0">' +
                    '<span class="lens-badge lens-badge--small lens-grid-item-badge ' + confCls + '">' + confPct + '%</span>' +
                    '<div class="lens-grid-item-image">' +
                    '<img src="' + self.escapeAttr(item.thumbnailUrl) + '" alt="' + self.escapeAttr(item.filename) + '" loading="lazy">' +
                    '</div>' +
                    '<div class="lens-grid-item-info">' +
                    '<div class="lens-grid-item-title">' + self.escapeHtml(item.suggestedTitle || item.filename) + '</div>' +
                    '<div class="lens-grid-item-meta">' + item.tagCount + ' ' + Craft.t('lens', 'tags') + '</div>' +
                    '</div></div>';
            });

            container.innerHTML = html;

            // Click handler: open in focus view
            container.querySelectorAll('.lens-browse-card').forEach(function(card) {
                card.addEventListener('click', function() {
                    var analysisId = parseInt(card.dataset.analysisId, 10);
                    self.openInFocusView(analysisId);
                });
                card.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        var analysisId = parseInt(card.dataset.analysisId, 10);
                        self.openInFocusView(analysisId);
                    }
                });
            });
        },

        renderBrowsePagination: function(page, totalPages) {
            var self = this;
            var el = document.getElementById('lens-browse-pagination');
            if (!el) return;

            if (totalPages <= 1) {
                el.innerHTML = '';
                return;
            }

            el.innerHTML = '<button type="button" class="btn small" id="lens-browse-prev"' + (page <= 1 ? ' disabled' : '') + '>' +
                Craft.t('lens', '&larr; Previous') + '</button>' +
                '<span class="lens-page-info">' + Craft.t('lens', 'Page {page} of {total}', {page: page, total: totalPages}) + '</span>' +
                '<button type="button" class="btn small" id="lens-browse-next"' + (page >= totalPages ? ' disabled' : '') + '>' +
                Craft.t('lens', 'Next &rarr;') + '</button>';

            var prevBtn = document.getElementById('lens-browse-prev');
            var nextBtn = document.getElementById('lens-browse-next');

            if (prevBtn) {
                prevBtn.addEventListener('click', function() {
                    self.loadBrowseView(page - 1);
                });
            }
            if (nextBtn) {
                nextBtn.addEventListener('click', function() {
                    self.loadBrowseView(page + 1);
                });
            }
        },

        openInFocusView: function(analysisId) {
            // Switch to single/focus mode and load the clicked analysis
            var idx = this.queueIds.indexOf(analysisId);
            this.currentIndex = idx !== -1 ? idx : 0;
            // Set a sentinel so switchMode doesn't auto-load
            this.currentData = 'loading';
            this.switchMode('single');
            this.currentData = null;
            this.loadAnalysis(analysisId);
        },

        // ==================================================================
        // Grid View
        // ==================================================================

        loadGridView: function() {
            var self = this;
            var container = document.getElementById('lens-grid-container');
            var loading = document.getElementById('lens-grid-loading');

            if (loading) loading.style.display = 'flex';
            this.gridSelection.clear();
            this.updateGridSelectionState();

            Craft.sendActionRequest('GET', 'lens/review/get-queue', {
                params: { page: 1, perPage: 100 }
            }).then(function(response) {
                    if (response.data.success) {
                        self.gridItems = response.data.items;
                        self.renderGridView();
                    }
                })
                .catch(function(error) {
                    console.error('[Lens] Grid load error:', error);
                    Craft.cp.displayError(Craft.t('lens', 'Failed to load grid view.'));
                })
                .finally(function() {
                    if (loading) loading.style.display = 'none';
                });
        },

        renderGridView: function() {
            var self = this;
            var container = document.getElementById('lens-grid-container');
            if (!container) return;

            if (this.gridItems.length === 0) {
                container.innerHTML = '<div class="lens-review-asset-meta" style="grid-column: 1/-1; text-align: center; padding: 40px;">' +
                    Craft.t('lens', 'No items in queue.') + '</div>';
                return;
            }

            var html = '';
            this.gridItems.forEach(function(item) {
                var confPct = Math.round((item.avgConfidence || 0) * 100);
                var confCls = item.avgConfidence >= 0.8 ? 'lens-badge--success' :
                    (item.avgConfidence >= 0.5 ? 'lens-badge--warning' : 'lens-badge--error');

                html += '<div class="lens-grid-item" data-analysis-id="' + item.analysisId + '">' +
                    '<div class="lens-grid-item-checkbox">' +
                    '<input type="checkbox" class="lens-grid-check" value="' + item.analysisId + '">' +
                    '</div>' +
                    '<span class="lens-badge lens-badge--small lens-grid-item-badge ' + confCls + '">' + confPct + '%</span>' +
                    '<div class="lens-grid-item-image">' +
                    '<img src="' + self.escapeAttr(item.thumbnailUrl) + '" alt="' + self.escapeAttr(item.filename) + '" loading="lazy">' +
                    '</div>' +
                    '<div class="lens-grid-item-info">' +
                    '<div class="lens-grid-item-title">' + self.escapeHtml(item.suggestedTitle || item.filename) + '</div>' +
                    '<div class="lens-grid-item-meta">' + item.tagCount + ' ' + Craft.t('lens', 'tags') + '</div>' +
                    '</div></div>';
            });

            container.innerHTML = html;

            // Bind checkbox events
            container.querySelectorAll('.lens-grid-check').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var id = parseInt(cb.value, 10);
                    var gridItem = cb.closest('.lens-grid-item');
                    if (cb.checked) {
                        self.gridSelection.add(id);
                        if (gridItem) gridItem.classList.add('selected');
                    } else {
                        self.gridSelection.delete(id);
                        if (gridItem) gridItem.classList.remove('selected');
                    }
                    self.updateGridSelectionState();
                });
            });

            // Click on grid item to toggle
            container.querySelectorAll('.lens-grid-item').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    if (e.target.tagName === 'INPUT') return;
                    var cb = item.querySelector('.lens-grid-check');
                    if (cb) {
                        cb.checked = !cb.checked;
                        cb.dispatchEvent(new Event('change'));
                    }
                });
            });
        },

        handleGridSelectAll: function() {
            var self = this;
            var allSelected = this.gridSelection.size === this.gridItems.length;
            var container = document.getElementById('lens-grid-container');

            container.querySelectorAll('.lens-grid-check').forEach(function(cb) {
                cb.checked = !allSelected;
                var id = parseInt(cb.value, 10);
                var gridItem = cb.closest('.lens-grid-item');
                if (!allSelected) {
                    self.gridSelection.add(id);
                    if (gridItem) gridItem.classList.add('selected');
                } else {
                    self.gridSelection.delete(id);
                    if (gridItem) gridItem.classList.remove('selected');
                }
            });

            this.updateGridSelectionState();
        },

        handleGridSelectHigh: function() {
            var self = this;
            this.gridSelection.clear();
            var container = document.getElementById('lens-grid-container');

            container.querySelectorAll('.lens-grid-check').forEach(function(cb) {
                cb.checked = false;
                var gridItem = cb.closest('.lens-grid-item');
                if (gridItem) gridItem.classList.remove('selected');
            });

            this.gridItems.forEach(function(item) {
                if (item.avgConfidence >= self.HIGH_CONFIDENCE_THRESHOLD) {
                    self.gridSelection.add(item.analysisId);
                    var cb = container.querySelector('.lens-grid-check[value="' + item.analysisId + '"]');
                    if (cb) {
                        cb.checked = true;
                        var gridItem = cb.closest('.lens-grid-item');
                        if (gridItem) gridItem.classList.add('selected');
                    }
                }
            });

            this.updateGridSelectionState();
        },

        updateGridSelectionState: function() {
            var count = this.gridSelection.size;
            var countEl = document.getElementById('lens-grid-selected-count');
            var approveBtn = document.getElementById('lens-grid-approve');
            var rejectBtn = document.getElementById('lens-grid-reject');

            if (countEl) countEl.textContent = count > 0 ? (count + ' ' + Craft.t('lens', 'selected')) : '';
            if (approveBtn) approveBtn.disabled = count === 0;
            if (rejectBtn) rejectBtn.disabled = count === 0;
        },

        handleGridApprove: function() {
            if (this.gridSelection.size === 0) return;
            var self = this;
            var ids = Array.from(this.gridSelection);

            var approveBtn = document.getElementById('lens-grid-approve');
            if (approveBtn) approveBtn.disabled = true;

            Craft.sendActionRequest('POST', 'lens/review/bulk-approve', {
                data: { ids: ids }
            }).then(function(response) {
                if (response.data.success) {
                    Craft.cp.displayNotice(
                        Craft.t('lens', '{count} analyses approved.', {count: response.data.count})
                    );
                    // Remove from queue
                    ids.forEach(function(id) { self.removeFromQueue(id); });
                    self.gridSelection.clear();
                    self.loadGridView();
                }
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Bulk approve failed.'));
                if (approveBtn) approveBtn.disabled = false;
            });
        },

        handleGridReject: function() {
            if (this.gridSelection.size === 0) return;
            var self = this;
            var ids = Array.from(this.gridSelection);

            var rejectBtn = document.getElementById('lens-grid-reject');
            if (rejectBtn) rejectBtn.disabled = true;

            Craft.sendActionRequest('POST', 'lens/review/bulk-reject', {
                data: { ids: ids }
            }).then(function(response) {
                if (response.data.success) {
                    Craft.cp.displayNotice(
                        Craft.t('lens', '{count} analyses rejected.', {count: response.data.count})
                    );
                    ids.forEach(function(id) { self.removeFromQueue(id); });
                    self.gridSelection.clear();
                    self.loadGridView();
                }
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Bulk reject failed.'));
                if (rejectBtn) rejectBtn.disabled = false;
            });
        },

        // ==================================================================
        // Utilities (delegate to Lens.utils)
        // ==================================================================

        escapeHtml: function(text) {
            return Lens.utils.escapeHtml(text);
        },

        escapeAttr: function(text) {
            return Lens.utils.escapeAttr(text);
        },

        formatFileSize: function(bytes) {
            return Lens.utils.formatFileSize(bytes);
        }
    };

    window.Lens.ReviewQueue = LensReviewQueue;
    window.Lens.SingleReview = LensSingleReview;

    // ==========================================================================
    // Initialize when DOM is ready
    // ==========================================================================

    function init() {
        LensReviewQueue.init();
        LensSingleReview.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
