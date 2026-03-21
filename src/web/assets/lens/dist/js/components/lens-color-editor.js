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

            DOM.delegate('[data-lens-control="color-picker"]', 'input', this._handlePickerInput.bind(this));

            DOM.delegate('[data-lens-control="color-hex-input"]', 'input', this._handleHexInput.bind(this));

            DOM.delegate('[data-lens-control="color-hex-input"]', 'keydown', this._handleHexKeydown.bind(this));

            DOM.delegate('[data-lens-action="color-add"]', 'click', this._handleColorAdd.bind(this));

            DOM.delegate('[data-lens-action="color-remove"]', 'click', this._handleColorRemove.bind(this));
        },

        // ================================================================
        // Event Handlers
        // ================================================================

        _handlePickerInput: function(e, picker) {
            var editor = picker.closest('[data-lens-target="color-editor"]');
            if (!editor) return;
            var hexInput = editor.querySelector('[data-lens-control="color-hex-input"]');
            if (hexInput) hexInput.value = picker.value.toUpperCase();
        },

        _handleHexInput: function(e, input) {
            var editor = input.closest('[data-lens-target="color-editor"]');
            if (!editor) return;
            var hex = input.value.trim();
            if (window.Lens.services.Taxonomy.validateHex(hex)) {
                var picker = editor.querySelector('[data-lens-control="color-picker"]');
                if (picker) picker.value = window.Lens.services.Taxonomy.normalizeHex(hex);
            }
        },

        _handleHexKeydown: function(e, input) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var editor = input.closest('[data-lens-target="color-editor"]');
                if (editor) this._addColorFromInputs(editor);
            }
        },

        _handleColorAdd: function(e, btn) {
            var editor = btn.closest('[data-lens-target="color-editor"]');
            if (editor) this._addColorFromInputs(editor);
        },

        _handleColorRemove: function(e, removeBtn) {
            e.preventDefault();
            e.stopPropagation();

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

        _addColorFromInputs: function(editor) {
            var hexInput = editor.querySelector('[data-lens-control="color-hex-input"]');
            var picker = editor.querySelector('[data-lens-control="color-picker"]');
            var hex = hexInput ? hexInput.value.trim() : (picker ? picker.value : '');
            if (!hex) return;

            if (!this._addColor(editor, hex)) return;

            if (hexInput) hexInput.value = '#000000';
            if (picker) picker.value = '#000000';

            this._updateColorCount(editor);
            this._autoSave(editor, Craft.t('lens', 'Color added.'));
        },

        _addColor: function(editor, hex) {
            if (!window.Lens.services.Taxonomy.validateHex(hex)) return false;

            const normalizedHex = window.Lens.services.Taxonomy.normalizeHex(hex);
            if (!normalizedHex) return false;

            if (window.Lens.services.Taxonomy.isDuplicateColor(editor, normalizedHex)) {
                this._flashDuplicateChip(editor, normalizedHex);
                Craft.cp.displayNotice(Craft.t('lens', 'Color already exists.'));
                return false;
            }

            var swatches = editor.querySelector('[data-lens-target="color-swatches"]');
            if (!swatches) return false;

            var template = editor.querySelector('[data-lens-target="color-item-template"]');
            if (!template) return false;

            var item = template.content.firstElementChild.cloneNode(true);
            item.dataset.lensHex = normalizedHex;
            item.href = Craft.getCpUrl('lens/search', {color: normalizedHex});
            item.querySelector('[data-lens-target="color-swatch"]').style.backgroundColor = normalizedHex;
            item.querySelector('[data-lens-target="chip-label"]').textContent = normalizedHex;

            swatches.appendChild(item);

            item.classList.add('lens-is-new');
            item.addEventListener('animationend', function() {
                this.classList.remove('lens-is-new');
            }, { once: true });

            return true;
        },

        // ================================================================
        // Color Count
        // ================================================================

        _updateColorCount: function(editor) {
            window.Lens.services.Taxonomy.updateItemCount(editor, '[data-lens-target="color-item"]', 'Colors');
        },

        // ================================================================
        // Auto-Save
        // ================================================================

        _autoSave: function(editor, successMessage) {
            var colors = window.Lens.services.Taxonomy.collectColors(editor);
            window.Lens.services.Taxonomy.autoSave(
                editor, 'lens/analysis/update-colors', 'colors', colors,
                successMessage, Craft.t('lens', 'Failed to save colors.')
            );
        },

        // ================================================================
        // Duplicate Feedback
        // ================================================================

        _flashDuplicateChip: function(editor, hex) {
            window.Lens.services.Taxonomy.flashDuplicateChip(
                editor, '[data-lens-target="color-item"]', hex,
                function(chip) { return chip.dataset.lensHex; }
            );
        }
    };

    window.Lens.components.ColorEditor = LensColorEditor;

    Lens.utils.onReady(function() {
        LensColorEditor.init();
    });
})();
