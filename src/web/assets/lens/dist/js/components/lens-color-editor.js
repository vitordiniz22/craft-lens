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

            DOM.delegate('[data-lens-control="color-picker"]', 'change', this._handlePickerChange.bind(this));

            DOM.delegate('[data-lens-action="color-remove"]', 'click', this._handleColorRemove.bind(this));
        },

        // ================================================================
        // Event Handlers
        // ================================================================

        _handlePickerChange: function(e, picker) {
            const editor = picker.closest('[data-lens-target="color-editor"]');
            if (!editor) return;

            const hex = picker.value;
            if (!this._addColor(editor, hex)) return;

            this._updateColorCount(editor);
            this._autoSave(editor, Craft.t('lens', 'Color added.'));
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

            var addBtn = swatches.querySelector('[data-lens-action="color-add"]');
            if (addBtn) {
                swatches.insertBefore(item, addBtn);
            } else {
                swatches.appendChild(item);
            }

            item.classList.add('is-new');
            item.addEventListener('animationend', function() {
                this.classList.remove('is-new');
            }, { once: true });

            return true;
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
        },

        // ================================================================
        // Duplicate Feedback
        // ================================================================

        _flashDuplicateChip: function(editor, hex) {
            var normalized = hex.toUpperCase();
            var chips = editor.querySelectorAll('[data-lens-target="color-item"]');

            for (var i = 0; i < chips.length; i++) {
                if (chips[i].dataset.lensHex.toUpperCase() === normalized) {
                    chips[i].classList.remove('is-duplicate-flash');
                    void chips[i].offsetWidth;
                    chips[i].classList.add('is-duplicate-flash');
                    chips[i].addEventListener('animationend', function() {
                        this.classList.remove('is-duplicate-flash');
                    }, { once: true });
                    break;
                }
            }
        }
    };

    window.Lens.components.ColorEditor = LensColorEditor;

    Lens.utils.onReady(function() {
        LensColorEditor.init();
    });
})();
