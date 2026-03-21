/**
 * Lens Plugin - Tag Editor Component
 * Tag management with autocomplete suggestions, keyboard navigation, and auto-save.
 * Auto-saves on each add/remove when data-lens-auto-save="1" is present (asset edit page).
 * In review mode, changes are collected at form submit time.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Tag Editor Component
     */
    const LensTagEditor = {
        _tagDebounce: null,
        _initialized: false,

        /**
         * Initialize tag editor
         */
        init: function() {
            if (this._initialized) return;
            if (!this._shouldInit()) return;
            this._bindEvents();
            this._initialized = true;
        },

        /**
         * Check if tag editor should initialize
         * @returns {boolean}
         * @private
         */
        _shouldInit: function() {
            return document.querySelector('[data-lens-target="tag-editor"]') !== null;
        },

        /**
         * Bind all event handlers
         * @private
         */
        _bindEvents: function() {
            const DOM = window.Lens.core.DOM;

            // Tag input keyboard handling (Enter to add, arrows to navigate suggestions)
            DOM.delegate('[data-lens-control="tag-input"]', 'keydown', this._handleTagKeydown.bind(this));

            // Tag input - autocomplete + length indicator
            DOM.delegate('[data-lens-control="tag-input"]', 'input', this._handleTagInput.bind(this));

            // Tag removal
            DOM.delegate('[data-lens-action="tag-remove"]', 'click', this._handleTagRemove.bind(this));

            // Suggestion selection
            DOM.delegate('[data-lens-target="tag-suggestion-item"]', 'click', this._handleSuggestionClick.bind(this));

            // Hide suggestions on blur
            DOM.delegate('[data-lens-control="tag-input"]', 'focusout', this._handleInputBlur.bind(this));

            // Add button
            DOM.delegate('[data-lens-action="tag-add"]', 'click', this._handleTagAddClick.bind(this));
        },

        // ================================================================
        // Event Handlers
        // ================================================================

        _handleTagKeydown: function(e, input) {
            if (e.key === 'Enter') {
                e.preventDefault();

                const editor = input.closest('[data-lens-target="tag-editor"]');
                const activeSuggestion = editor ? editor.querySelector('[data-lens-target="tag-suggestion-item"].lens-is-active') : null;

                if (activeSuggestion) {
                    this._selectSuggestion(editor, activeSuggestion);
                } else {
                    this._addTagFromInput(editor);
                }
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                const editor = input.closest('[data-lens-target="tag-editor"]');
                const suggestionsEl = editor ? editor.querySelector('[data-lens-target="tag-suggestions"]') : null;
                if (!suggestionsEl || !suggestionsEl.classList.contains('lens-is-visible')) return;

                e.preventDefault();

                const items = suggestionsEl.querySelectorAll('[data-lens-target="tag-suggestion-item"]');
                const active = suggestionsEl.querySelector('[data-lens-target="tag-suggestion-item"].lens-is-active');
                let idx = active ? Array.from(items).indexOf(active) : -1;

                if (active) active.classList.remove('lens-is-active');

                if (e.key === 'ArrowDown') {
                    idx = (idx + 1) % items.length;
                } else {
                    idx = idx <= 0 ? items.length - 1 : idx - 1;
                }

                items[idx].classList.add('lens-is-active');
            }
        },

        _handleTagInput: function(e, input) {
            const query = input.value.trim();
            const editor = input.closest('[data-lens-target="tag-editor"]');
            if (!editor) return;

            this._updateLengthIndicator(input);

            const minChars = window.Lens.config.THRESHOLDS.TAG_AUTOCOMPLETE_MIN_CHARS;
            if (query.length < minChars) {
                this._hideSuggestions(editor);
                return;
            }

            this._loadSuggestions(editor, query);
        },

        _handleTagAddClick: function(e, btn) {
            var editor = btn.closest('[data-lens-target="tag-editor"]');
            if (editor) this._addTagFromInput(editor);
        },

        _handleTagRemove: function(e, removeBtn) {
            e.preventDefault();
            e.stopPropagation();

            const chip = removeBtn.closest('[data-lens-tag]');
            if (!chip) return;

            const editor = chip.closest('[data-lens-target="tag-editor"]');
            chip.remove();

            if (editor) {
                var input = editor.querySelector('[data-lens-control="tag-input"]');
                if (input) input.focus();
            }

            this._updateTagCount(editor);
            this._autoSave(editor, Craft.t('lens', 'Tag removed.'));
        },

        _handleSuggestionClick: function(e, suggestion) {
            const editor = suggestion.closest('[data-lens-target="tag-editor"]');
            if (editor) {
                this._selectSuggestion(editor, suggestion);
            }
        },

        _handleInputBlur: function(e, input) {
            // Delay to allow click events to fire first
            setTimeout(() => {
                const editor = input.closest('[data-lens-target="tag-editor"]');
                if (editor) this._hideSuggestions(editor);
            }, window.Lens.config.ANIMATION.SUGGESTION_DELAY_MS);
        },

        // ================================================================
        // Tag Management
        // ================================================================

        _addTagFromInput: function(editor) {
            var input = editor.querySelector('[data-lens-control="tag-input"]');
            if (!input) return;
            var tagName = input.value.trim();
            if (!tagName) return;
            this._commitTag(editor, tagName);
        },

        _selectSuggestion: function(editor, suggestion) {
            var tagName = suggestion.dataset.lensSuggestionTag;
            this._commitTag(editor, tagName);
        },

        /**
         * Shared commit flow for adding a tag (from input or suggestion).
         * Handles duplicate check, chip creation, cleanup, and auto-save.
         * @returns {boolean} false if duplicate
         */
        _commitTag: function(editor, tagName) {
            var input = editor.querySelector('[data-lens-control="tag-input"]');
            if (input) {
                input.value = '';
                this._clearLengthIndicator(input);
            }
            this._hideSuggestions(editor);

            if (window.Lens.services.Taxonomy.isDuplicateTag(editor, tagName)) {
                this._flashDuplicateChip(editor, tagName);
                Craft.cp.displayNotice(Craft.t('lens', 'Tag already exists.'));
                return false;
            }

            this._addTag(editor, tagName, false);
            this._updateTagCount(editor);
            this._autoSave(editor, Craft.t('lens', 'Tag added.'));
            return true;
        },

        _addTag: function(editor, tagName, isAi) {
            const chips = window.Lens.services.Taxonomy.getOrCreateChipsContainer(editor);

            if (!chips) return;

            var template = editor.querySelector('[data-lens-target="tag-chip-template"]');

            if (!template) return;

            var chip = template.content.firstElementChild.cloneNode(true);

            chip.href = Craft.getCpUrl('lens/search', {tags: tagName});
            chip.dataset.lensTag = tagName;
            chip.dataset.lensIsAi = isAi ? '1' : '0';
            chip.dataset.lensConfidence = isAi ? '' : '1';
            chip.querySelector('[data-lens-target="chip-label"]').textContent = tagName;

            chips.appendChild(chip);

            chip.classList.add('lens-is-new');
            chip.addEventListener('animationend', function() {
                this.classList.remove('lens-is-new');
            }, { once: true });
        },

        // ================================================================
        // Autocomplete
        // ================================================================

        _loadSuggestions: function(editor, query) {
            if (this._tagDebounce) clearTimeout(this._tagDebounce);

            const debounceMs = window.Lens.config.POLLING.TAG_AUTOCOMPLETE_DEBOUNCE_MS;
            this._tagDebounce = setTimeout(() => {
                window.Lens.core.API.fetchTagSuggestions(query).then((response) => {
                    const tags = response.data.tags || [];
                    this._showSuggestions(editor, tags);
                }).catch(() => {
                    // Silently fail for autocomplete
                    this._hideSuggestions(editor);
                });
            }, debounceMs);
        },

        _showSuggestions: function(editor, tags) {
            const suggestionsEl = editor.querySelector('[data-lens-target="tag-suggestions"]');
            if (!suggestionsEl) return;

            if (!tags.length) {
                suggestionsEl.classList.remove('lens-is-visible');
                return;
            }

            suggestionsEl.innerHTML = '';
            tags.forEach((tag) => {
                const item = document.createElement('div');
                item.className = 'lens-tag-suggestion';
                item.dataset.lensTarget = 'tag-suggestion-item';
                item.dataset.lensSuggestionTag = tag.tag;
                item.innerHTML =
                    '<span>' + window.Lens.utils.escapeHtml(tag.tag) + '</span>' +
                    '<span class="lens-tag-suggestion-count">' + tag.count + '</span>';
                suggestionsEl.appendChild(item);
            });

            suggestionsEl.classList.add('lens-is-visible');
        },

        _hideSuggestions: function(editor) {
            const suggestionsEl = editor.querySelector('[data-lens-target="tag-suggestions"]');
            if (suggestionsEl) {
                suggestionsEl.classList.remove('lens-is-visible');
            }
        },

        // ================================================================
        // Tag Count
        // ================================================================

        _updateTagCount: function(editor) {
            window.Lens.services.Taxonomy.updateItemCount(editor, '[data-lens-tag]', 'Tags');
        },

        // ================================================================
        // Auto-Save
        // ================================================================

        _autoSave: function(editor, successMessage) {
            var tags = window.Lens.services.Taxonomy.collectTags(editor);
            window.Lens.services.Taxonomy.autoSave(
                editor, 'lens/analysis/update-tags', 'tags', tags,
                successMessage, Craft.t('lens', 'Failed to save tags.')
            );
        },

        // ================================================================
        // Duplicate Feedback
        // ================================================================

        _flashDuplicateChip: function(editor, tagName) {
            window.Lens.services.Taxonomy.flashDuplicateChip(
                editor, '[data-lens-tag]', tagName,
                function(chip) { return chip.dataset.lensTag; }
            );
        },

        // ================================================================
        // Length Indicator
        // ================================================================

        _updateLengthIndicator: function(input) {
            var wrapper = input.closest('[data-lens-target="tag-input-wrapper"]');
            if (!wrapper) return;

            var indicator = wrapper.querySelector('[data-lens-target="length-indicator"]');
            var length = input.value.length;
            var threshold = window.Lens.config.THRESHOLDS.TAG_LENGTH_WARNING_THRESHOLD;
            var max = window.Lens.config.THRESHOLDS.TAG_MAX_LENGTH;

            if (length >= threshold) {
                if (!indicator) {
                    indicator = document.createElement('span');
                    indicator.className = 'lens-tag-length-indicator';
                    indicator.dataset.lensTarget = 'length-indicator';
                    wrapper.appendChild(indicator);
                }
                indicator.textContent = length + '/' + max;
                indicator.classList.toggle('lens-is-near-limit', length >= window.Lens.config.THRESHOLDS.TAG_LENGTH_NEAR_LIMIT);
            } else if (indicator) {
                indicator.remove();
            }
        },

        _clearLengthIndicator: function(input) {
            var wrapper = input.closest('[data-lens-target="tag-input-wrapper"]');
            if (!wrapper) return;

            var indicator = wrapper.querySelector('[data-lens-target="length-indicator"]');
            if (indicator) indicator.remove();
        }
    };

    window.Lens.components.TagEditor = LensTagEditor;

    // Auto-initialize
    Lens.utils.onReady(function() {
        LensTagEditor.init();
    });
})();
