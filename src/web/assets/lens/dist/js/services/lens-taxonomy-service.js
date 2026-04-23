/**
 * Lens Plugin - Taxonomy Service
 * Centralized tag operations.
 * Eliminates duplicate validation and collection logic.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.services = window.Lens.services || {};

    /**
     * Taxonomy Service for tags.
     */
    window.Lens.services.Taxonomy = {
        /**
         * Check if a tag already exists in the editor
         * @param {HTMLElement} container - Tag editor container
         * @param {string} tagName - Tag name to check
         * @returns {boolean} True if tag exists (case-insensitive)
         */
        isDuplicateTag: function(container, tagName) {
            if (!container || !tagName) return false;

            const existing = container.querySelectorAll('[data-lens-tag]');
            const normalizedTag = tagName.toLowerCase();

            for (let i = 0; i < existing.length; i++) {
                const existingTag = existing[i].dataset.lensTag;
                if (existingTag && existingTag.toLowerCase() === normalizedTag) {
                    return true;
                }
            }

            return false;
        },

        /**
         * Collect all tags from editor
         * @param {HTMLElement} container - Tag editor container
         * @returns {Array<{name: string, isAi: boolean, confidence: number|string}>} Array of tag data
         */
        collectTags: function(container) {
            if (!container) return [];

            const chips = container.querySelectorAll('[data-lens-tag]');
            const tags = [];

            chips.forEach(function(chip) {
                const tag = {
                    name: chip.dataset.lensTag || '',
                    isAi: chip.dataset.lensIsAi === '1',
                    confidence: chip.dataset.lensConfidence || ''
                };
                if (tag.name) {
                    tags.push(tag);
                }
            });

            return tags;
        },

        /**
         * Create or get chips container for tags
         * @param {HTMLElement} editor - Tag editor element
         * @returns {HTMLElement|null} Chips container
         */
        getOrCreateChipsContainer: function(editor) {
            return this._getOrCreateContainer(editor, 'tag-chips', 'lens-tag-chips');
        },

        // --------------------------------------------------------------------
        // Auto-save for tag edits.
        // --------------------------------------------------------------------

        /**
         * Auto-save tags via AJAX.
         * Guards on data-lens-auto-save="1" and data-lens-analysis-id.
         * @param {HTMLElement} editor - Editor container
         * @param {string} action - Craft action path (e.g., 'lens/analysis/update-tags')
         * @param {string} payloadKey - Key for the JSON payload (e.g., 'tags')
         * @param {Array} items - Collected items to save
         * @param {string} successMessage - Notice on success
         * @param {string} errorMessage - Notice on failure
         */
        autoSave: function(editor, action, payloadKey, items, successMessage, errorMessage) {
            if (!editor || editor.dataset.lensAutoSave !== '1') return;
            var analysisId = editor.dataset.lensAnalysisId;
            if (!analysisId) return;

            var data = { analysisId: analysisId };
            data[payloadKey] = JSON.stringify(items);

            window.Lens.core.API.post(action, data).then(function() {
                Craft.cp.displayNotice(successMessage);
            }).catch(function() {
                Craft.cp.displayError(errorMessage);
            });
        },

        // --------------------------------------------------------------------
        // DOM helpers for TagEditor.
        // --------------------------------------------------------------------

        /**
         * Flash a duplicate chip to indicate a duplicate add attempt.
         * @param {HTMLElement} editor - Editor container
         * @param {string} itemSelector - Selector for chip elements (e.g., '[data-lens-tag]')
         * @param {string} value - Value to match against
         * @param {Function} getValue - Function to extract value from chip element
         */
        flashDuplicateChip: function(editor, itemSelector, value, getValue) {
            var chips = editor.querySelectorAll(itemSelector);

            for (var i = 0; i < chips.length; i++) {
                if (getValue(chips[i]).toLowerCase() === value.toLowerCase()) {
                    chips[i].classList.remove('lens-is-duplicate-flash');
                    void chips[i].offsetWidth;
                    chips[i].classList.add('lens-is-duplicate-flash');
                    chips[i].addEventListener('animationend', function() {
                        this.classList.remove('lens-is-duplicate-flash');
                    }, { once: true });
                    break;
                }
            }
        },

        /**
         * Update item count label for the tag editor.
         * @param {HTMLElement} editor - Editor container
         * @param {string} itemSelector - Selector for countable items
         * @param {string} labelKey - Translation key (e.g., 'Tags')
         */
        updateItemCount: function(editor, itemSelector, labelKey) {
            if (!editor) return;

            var count = editor.querySelectorAll(itemSelector).length;
            var label = editor.querySelector('[data-lens-target="field-label"]');
            if (label) {
                label.textContent = Craft.t('lens', labelKey) + ' (' + count + ')';
            }
        },

        /**
         * Generic container creation for tag chips.
         * @private
         */
        _getOrCreateContainer: function(editor, targetName, className) {
            if (!editor) return null;

            var container = editor.querySelector('[data-lens-target="' + targetName + '"]');
            if (!container) {
                var emptyState = editor.querySelector('[data-lens-target="empty-state"]');
                if (emptyState) emptyState.remove();

                container = document.createElement('div');
                container.className = className;
                container.dataset.lensTarget = targetName;

                var labelRow = editor.querySelector('[data-lens-target="field-header"]');
                if (labelRow) {
                    labelRow.parentNode.insertBefore(container, labelRow.nextSibling);
                }
            }

            return container;
        }
    };
})();
