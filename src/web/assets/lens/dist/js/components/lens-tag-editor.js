/**
 * Lens Plugin - Tag Editor Component
 * Tag management with autocomplete suggestions and keyboard navigation
 * Extracted and refactored from lens-asset-actions.js
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

            // Tag input - autocomplete
            DOM.delegate('[data-lens-control="tag-input"]', 'input', this._handleTagInput.bind(this));

            // Tag removal
            DOM.delegate('[data-lens-action="tag-remove"]', 'click', this._handleTagRemove.bind(this));

            // Suggestion selection
            DOM.delegate('[data-lens-target="tag-suggestion-item"]', 'click', this._handleSuggestionClick.bind(this));

            // Hide suggestions on blur
            DOM.delegate('[data-lens-control="tag-input"]', 'focusout', this._handleInputBlur.bind(this));
        },

        // ================================================================
        // Event Handlers
        // ================================================================

        _handleTagKeydown: function(e, input) {
            if (e.key === 'Enter') {
                e.preventDefault();

                const editor = input.closest('[data-lens-target="tag-editor"]');
                const activeSuggestion = editor ? editor.querySelector('[data-lens-target="tag-suggestion-item"].is-active') : null;

                if (activeSuggestion) {
                    this._selectSuggestion(editor, activeSuggestion);
                } else {
                    this._addTagFromInput(editor);
                }
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                const editor = input.closest('[data-lens-target="tag-editor"]');
                const suggestionsEl = editor ? editor.querySelector('[data-lens-target="tag-suggestions"]') : null;
                if (!suggestionsEl || !suggestionsEl.classList.contains('is-visible')) return;

                e.preventDefault();

                const items = suggestionsEl.querySelectorAll('[data-lens-target="tag-suggestion-item"]');
                const active = suggestionsEl.querySelector('[data-lens-target="tag-suggestion-item"].is-active');
                let idx = active ? Array.prototype.indexOf.call(items, active) : -1;

                if (active) active.classList.remove('is-active');

                if (e.key === 'ArrowDown') {
                    idx = (idx + 1) % items.length;
                } else {
                    idx = idx <= 0 ? items.length - 1 : idx - 1;
                }

                items[idx].classList.add('is-active');
            }
        },

        _handleTagInput: function(e, input) {
            const query = input.value.trim();
            const editor = input.closest('[data-lens-target="tag-editor"]');
            if (!editor) return;

            const minChars = window.Lens.config.THRESHOLDS.TAG_AUTOCOMPLETE_MIN_CHARS;
            if (query.length < minChars) {
                this._hideSuggestions(editor);
                return;
            }

            this._loadSuggestions(editor, query);
        },

        _handleTagRemove: function(e, removeBtn) {
            e.preventDefault();
            e.stopPropagation();

            const chip = removeBtn.closest('.chip');
            if (!chip) return;

            chip.remove();
            this._markDirty(removeBtn);
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
            const input = editor.querySelector('[data-lens-control="tag-input"]');
            if (!input) return;

            const tagName = input.value.trim();
            if (!tagName) return;

            // Use service to check for duplicates
            if (window.Lens.services.Taxonomy.isDuplicateTag(editor, tagName)) {
                input.value = '';
                return;
            }

            this._addTag(editor, tagName, false);
            input.value = '';
            this._hideSuggestions(editor);
            this._markDirty(editor);
        },

        _selectSuggestion: function(editor, suggestion) {
            const tagName = suggestion.dataset.lensTag;
            const input = editor.querySelector('[data-lens-control="tag-input"]');
            if (input) input.value = '';

            // Use service to check for duplicates
            if (window.Lens.services.Taxonomy.isDuplicateTag(editor, tagName)) {
                this._hideSuggestions(editor);
                return;
            }

            this._addTag(editor, tagName, false);
            this._hideSuggestions(editor);
            this._markDirty(editor);
        },

        _addTag: function(editor, tagName, isAi) {
            // Use service to get or create chips container
            const chips = window.Lens.services.Taxonomy.getOrCreateChipsContainer(editor);
            if (!chips) return;

            const chip = document.createElement('a');
            chip.href = Craft.getCpUrl('lens/search', {tags: tagName});
            chip.className = 'chip';
            chip.dataset.lensTag = tagName;
            chip.dataset.lensIsAi = isAi ? '1' : '0';
            chip.dataset.lensConfidence = isAi ? '' : '1';
            chip.innerHTML =
                '<div class="chip-content">' +
                    '<span class="chip-label">' + window.Lens.utils.escapeHtml(tagName) + '</span>' +
                    '<button type="button" class="lens-tag-remove" data-lens-action="tag-remove" title="' +
                    Craft.t('lens', 'Remove') + '">&times;</button>' +
                '</div>';

            chips.appendChild(chip);
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
                suggestionsEl.classList.remove('is-visible');
                return;
            }

            suggestionsEl.innerHTML = '';
            tags.forEach((tag) => {
                const item = document.createElement('div');
                item.className = 'lens-tag-suggestion';
                item.dataset.lensTarget = 'tag-suggestion-item';
                item.dataset.lensTag = tag.tag;
                item.innerHTML =
                    '<span>' + window.Lens.utils.escapeHtml(tag.tag) + '</span>' +
                    '<span class="lens-tag-suggestion-count">' + tag.count + '</span>';
                suggestionsEl.appendChild(item);
            });

            suggestionsEl.classList.add('is-visible');
        },

        _hideSuggestions: function(editor) {
            const suggestionsEl = editor.querySelector('[data-lens-target="tag-suggestions"]');
            if (suggestionsEl) {
                suggestionsEl.classList.remove('is-visible');
            }
        },

        // ================================================================
        // Helpers
        // ================================================================

        _markDirty: function(el) {
            const section = el.closest('.lens-section');
            if (!section) return;

            const saveBtn = section.querySelector('[data-lens-action="taxonomy-save"]');
            if (saveBtn) {
                saveBtn.disabled = false;
                const status = section.querySelector('[data-lens-target="taxonomy-status"]');
                if (status) {
                    status.textContent = Craft.t('lens', 'Unsaved changes');
                }
            }
        }
    };

    window.Lens.components.TagEditor = LensTagEditor;

    // Auto-initialize
    function init() {
        LensTagEditor.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
