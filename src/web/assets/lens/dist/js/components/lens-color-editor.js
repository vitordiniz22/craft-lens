/**
 * Lens Plugin - Color Editor Component
 * Color picker and swatch management
 * Extracted and refactored from lens-asset-actions.js
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Color Editor Component
     */
    const LensColorEditor = {
        /**
         * Initialize color editor
         */
        init: function() {
            if (!this._shouldInit()) return;
            this._bindEvents();
        },

        /**
         * Check if color editor should initialize
         * @returns {boolean}
         * @private
         */
        _shouldInit: function() {
            return document.querySelector('[data-lens-target="color-editor"]') !== null;
        },

        /**
         * Bind all event handlers
         * @private
         */
        _bindEvents: function() {
            const DOM = window.Lens.core.DOM;

            // Add color
            DOM.delegate('[data-lens-action="color-add"]', 'click', this._handleColorAdd.bind(this));

            // Remove color
            DOM.delegate('[data-lens-action="color-remove"]', 'click', this._handleColorRemove.bind(this));
        },

        // ================================================================
        // Event Handlers
        // ================================================================

        _handleColorAdd: function(e, addBtn) {
            const editor = addBtn.closest('[data-lens-target="color-editor"]');
            if (!editor) return;

            const picker = editor.querySelector('[data-lens-control="color-picker"]');
            if (!picker) return;

            const hex = picker.value;
            this._addColor(editor, hex);
            this._markDirty(editor);
        },

        _handleColorRemove: function(e, removeBtn) {
            const item = removeBtn.closest('[data-lens-target="color-item"]');
            if (!item) return;

            item.remove();
            this._markDirty(removeBtn);
        },

        // ================================================================
        // Color Management
        // ================================================================

        _addColor: function(editor, hex) {
            // Use service to validate and normalize hex
            if (!window.Lens.services.Taxonomy.validateHex(hex)) return;

            const normalizedHex = window.Lens.services.Taxonomy.normalizeHex(hex);
            if (!normalizedHex) return;

            // Use service to check for duplicates
            if (window.Lens.services.Taxonomy.isDuplicateColor(editor, normalizedHex)) return;

            // Use service to get or create swatches container
            const swatches = window.Lens.services.Taxonomy.getOrCreateSwatchesContainer(editor);
            if (!swatches) return;

            const item = document.createElement('div');
            item.className = 'lens-color-item';
            item.dataset.lensTarget = 'color-item';
            item.dataset.lensHex = normalizedHex;
            item.dataset.lensIsAi = '0';
            item.dataset.lensPercentage = '';
            item.innerHTML =
                '<span class="lens-swatch lens-swatch--sm" style="background-color: ' + normalizedHex + '"></span>' +
                '<span>' + normalizedHex + '</span>' +
                '<button type="button" class="lens-color-remove" data-lens-action="color-remove" title="' +
                Craft.t('lens', 'Remove') + '">&times;</button>';

            swatches.appendChild(item);
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

    window.Lens.components.ColorEditor = LensColorEditor;

    // Auto-initialize
    function init() {
        LensColorEditor.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
