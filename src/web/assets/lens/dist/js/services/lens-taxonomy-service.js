/**
 * Lens Plugin - Taxonomy Service
 * Centralized tag and color operations
 * Eliminates duplicate validation and collection logic
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.services = window.Lens.services || {};

    /**
     * Taxonomy Service for tags and colors
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
         * Check if a color already exists in the editor
         * @param {HTMLElement} container - Color editor container
         * @param {string} hex - Hex color to check (with or without #)
         * @returns {boolean} True if color exists (case-insensitive)
         */
        isDuplicateColor: function(container, hex) {
            if (!container || !hex) return false;

            // Normalize hex (ensure it has #)
            const normalizedHex = hex.startsWith('#') ? hex : '#' + hex;
            const existing = container.querySelectorAll('[data-lens-target="color-item"]');

            for (let i = 0; i < existing.length; i++) {
                const existingHex = existing[i].dataset.lensHex;
                if (existingHex && existingHex.toLowerCase() === normalizedHex.toLowerCase()) {
                    return true;
                }
            }

            return false;
        },

        /**
         * Validate hex color format
         * @param {string} hex - Hex color to validate
         * @returns {boolean} True if valid hex color
         */
        validateHex: function(hex) {
            if (!hex) return false;
            return /^#?[0-9A-Fa-f]{6}$/.test(hex);
        },

        /**
         * Normalize hex color (ensure # prefix and uppercase)
         * @param {string} hex - Hex color to normalize
         * @returns {string|null} Normalized hex or null if invalid
         */
        normalizeHex: function(hex) {
            if (!this.validateHex(hex)) return null;

            const cleanHex = hex.replace('#', '');
            return '#' + cleanHex.toUpperCase();
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
         * Collect all colors from editor
         * @param {HTMLElement} container - Color editor container
         * @returns {Array<{hex: string, isAi: boolean, percentage: number|string}>} Array of color data
         */
        collectColors: function(container) {
            if (!container) return [];

            const items = container.querySelectorAll('[data-lens-target="color-item"]');
            const colors = [];

            items.forEach(function(item) {
                const color = {
                    hex: item.dataset.lensHex || '',
                    isAi: item.dataset.lensIsAi === '1',
                    percentage: item.dataset.lensPercentage || ''
                };
                if (color.hex) {
                    colors.push(color);
                }
            });

            return colors;
        },

        /**
         * Create or get chips container for tags
         * @param {HTMLElement} editor - Tag editor element
         * @returns {HTMLElement|null} Chips container
         */
        getOrCreateChipsContainer: function(editor) {
            return this._getOrCreateContainer(editor, 'tag-chips', 'lens-tag-chips');
        },

        /**
         * Create or get swatches container for colors
         * @param {HTMLElement} editor - Color editor element
         * @returns {HTMLElement|null} Swatches container
         */
        getOrCreateSwatchesContainer: function(editor) {
            return this._getOrCreateContainer(editor, 'color-swatches', 'lens-tag-chips');
        },

        // --------------------------------------------------------------------
        // DOM helpers shared by TagEditor and ColorEditor.
        // These live in the service (rather than each component) to stay DRY.
        // They only manipulate DOM within the editor containers passed to them.
        // --------------------------------------------------------------------

        /**
         * Flash a duplicate chip to indicate a duplicate add attempt.
         * Shared by tag and color editors.
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
         * Update item count label for tag/color editors.
         * @param {HTMLElement} editor - Editor container
         * @param {string} itemSelector - Selector for countable items
         * @param {string} labelKey - Translation key (e.g., 'Tags', 'Colors')
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
         * Generic container creation for tag chips or color swatches.
         * @private
         */
        _getOrCreateContainer: function(editor, targetName, className) {
            if (!editor) return null;

            var container = editor.querySelector('[data-lens-target="' + targetName + '"]');
            if (!container) {
                // Remove empty state ("No tags" / "No colors" message)
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
