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

            const existing = container.querySelectorAll('.chip');
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

            const chips = container.querySelectorAll('.chip');
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
         * Count tags in editor
         * @param {HTMLElement} container - Tag editor container
         * @returns {number} Number of tags
         */
        countTags: function(container) {
            if (!container) return 0;
            return container.querySelectorAll('.chip').length;
        },

        /**
         * Count colors in editor
         * @param {HTMLElement} container - Color editor container
         * @returns {number} Number of colors
         */
        countColors: function(container) {
            if (!container) return 0;
            return container.querySelectorAll('[data-lens-target="color-item"]').length;
        },

        /**
         * Check if tag editor is empty
         * @param {HTMLElement} container - Tag editor container
         * @returns {boolean} True if no tags
         */
        isTagEditorEmpty: function(container) {
            return this.countTags(container) === 0;
        },

        /**
         * Check if color editor is empty
         * @param {HTMLElement} container - Color editor container
         * @returns {boolean} True if no colors
         */
        isColorEditorEmpty: function(container) {
            return this.countColors(container) === 0;
        },

        /**
         * Get empty state element (the "No tags" or "No colors" message)
         * @param {HTMLElement} container - Editor container
         * @returns {HTMLElement|null} Empty state element
         */
        getEmptyState: function(container) {
            if (!container) return null;
            return container.querySelector('p.light');
        },

        /**
         * Remove empty state element
         * @param {HTMLElement} container - Editor container
         */
        removeEmptyState: function(container) {
            const emptyState = this.getEmptyState(container);
            if (emptyState) {
                emptyState.remove();
            }
        },

        /**
         * Create or get chips container for tags
         * @param {HTMLElement} editor - Tag editor element
         * @returns {HTMLElement|null} Chips container
         */
        getOrCreateChipsContainer: function(editor) {
            if (!editor) return null;

            let chips = editor.querySelector('[data-lens-target="tag-chips"]');
            if (!chips) {
                // Remove empty state
                this.removeEmptyState(editor);

                // Create chips container
                chips = document.createElement('div');
                chips.className = 'lens-tag-chips';
                chips.dataset.lensTarget = 'tag-chips';

                // Insert after label row
                const labelRow = editor.querySelector('.flex');
                if (labelRow) {
                    labelRow.parentNode.insertBefore(chips, labelRow.nextSibling);
                }
            }

            return chips;
        },

        /**
         * Create or get swatches container for colors
         * @param {HTMLElement} editor - Color editor element
         * @returns {HTMLElement|null} Swatches container
         */
        getOrCreateSwatchesContainer: function(editor) {
            if (!editor) return null;

            let swatches = editor.querySelector('[data-lens-target="color-swatches"]');
            if (!swatches) {
                // Remove empty state
                this.removeEmptyState(editor);

                // Create swatches container
                swatches = document.createElement('div');
                swatches.className = 'lens-color-swatches';
                swatches.dataset.lensTarget = 'color-swatches';

                // Insert after label row
                const labelRow = editor.querySelector('.flex');
                if (labelRow) {
                    labelRow.parentNode.insertBefore(swatches, labelRow.nextSibling);
                }
            }

            return swatches;
        }
    };
})();
