/**
 * Lens Plugin - Color Editor Component
 * Color picker and swatch management with auto-save.
 * Auto-saves on each add/remove when data-lens-auto-save="1" is present (asset edit page).
 * In review mode, changes are collected at form submit time.
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
            this._updateColorCount(editor);
            this._autoSave(editor, Craft.t('lens', 'Color added.'));
        },

        _handleColorRemove: function(e, removeBtn) {
            const item = removeBtn.closest('[data-lens-target="color-item"]');
            if (!item) return;

            const editor = item.closest('[data-lens-target="color-editor"]');
            item.remove();
            this._updateColorCount(editor);
            this._autoSave(editor, Craft.t('lens', 'Color removed.'));
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

            // Scale-in animation
            item.classList.add('is-new');
            item.addEventListener('animationend', function() {
                this.classList.remove('is-new');
            }, { once: true });
        },

        // ================================================================
        // Color Count
        // ================================================================

        _updateColorCount: function(editor) {
            if (!editor) return;

            var count = editor.querySelectorAll('[data-lens-target="color-item"]').length;
            var label = editor.querySelector('[data-lens-target="field-header"] .lens-field-label');
            if (label) {
                label.textContent = Craft.t('lens', 'Colors') + ' (' + count + ')';
            }
        },

        // ================================================================
        // Auto-Save
        // ================================================================

        _autoSave: function(editor, successMessage) {
            if (!editor) return;

            // Only auto-save when data-lens-auto-save="1" (asset edit page, not review)
            if (editor.dataset.lensAutoSave !== '1') return;

            var analysisId = editor.dataset.lensAnalysisId;
            if (!analysisId) return;

            var colors = window.Lens.services.Taxonomy.collectColors(editor);

            window.Lens.core.API.post('lens/analysis/update-colors', {
                analysisId: analysisId,
                colors: JSON.stringify(colors)
            }).then(function() {
                Craft.cp.displayNotice(successMessage);
            }).catch(function() {
                Craft.cp.displayError(Craft.t('lens', 'Failed to save colors.'));
            });
        }
    };

    window.Lens.components.ColorEditor = LensColorEditor;

    // Auto-initialize
    Lens.utils.onReady(function() {
        LensColorEditor.init();
    });
})();
