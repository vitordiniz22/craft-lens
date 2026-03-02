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
        _initialized: false,

        /**
         * Initialize color editor
         */
        init: function() {
            if (this._initialized) return;
            if (!this._shouldInit()) return;
            this._bindEvents();
            this._initialized = true;
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

            var template = editor.querySelector('[data-lens-target="color-item-template"]');
            if (!template) return;

            var item = template.content.firstElementChild.cloneNode(true);
            item.dataset.lensHex = normalizedHex;
            item.href = Craft.getCpUrl('lens/search', {color: normalizedHex});
            item.querySelector('[data-lens-target="color-swatch"]').style.backgroundColor = normalizedHex;
            item.querySelector('[data-lens-target="chip-label"]').textContent = normalizedHex;

            swatches.appendChild(item);
        },

        _markDirty: function(el) {
            window.Lens.services.Taxonomy.markDirty(el);
        }
    };

    window.Lens.components.ColorEditor = LensColorEditor;

    // Auto-initialize
    Lens.utils.onReady(function() {
        LensColorEditor.init();
    });
})();
