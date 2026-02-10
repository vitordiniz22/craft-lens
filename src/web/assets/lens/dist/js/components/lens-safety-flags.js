/**
 * Lens Plugin - Safety Flags Component
 * Safety flag dismissal (NSFW, violence, etc.)
 * Extracted and refactored from lens-asset-actions.js
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Safety Flags Component
     */
    const LensSafetyFlags = {
        _initialized: false,

        /**
         * Initialize safety flags component
         */
        init: function() {
            if (this._initialized) return;
            if (!this._shouldInit()) return;
            this._bindEvents();
            this._initialized = true;
        },

        /**
         * Check if safety flags should initialize
         * @returns {boolean}
         * @private
         */
        _shouldInit: function() {
            return document.querySelector('[data-lens-action="flag-dismiss"]') !== null ||
                   document.querySelector('[data-lens-action="flag-revert"]') !== null;
        },

        /**
         * Bind all event handlers
         * @private
         */
        _bindEvents: function() {
            window.Lens.core.DOM.delegate('[data-lens-action="flag-dismiss"]', 'click', this._handleDismiss.bind(this));
            window.Lens.core.DOM.delegate('[data-lens-action="flag-revert"]', 'click', this._handleRevert.bind(this));
        },

        // ================================================================
        // Event Handlers
        // ================================================================

        _handleDismiss: function(e, dismissBtn) {
            const analysisId = dismissBtn.dataset.lensAnalysisId;
            const field = dismissBtn.dataset.lensField;
            const value = dismissBtn.dataset.lensValue;

            // For boolean fields, use update-field; for scores, set to 0
            const sendValue = value !== undefined ? value : false;

            window.Lens.core.ButtonState.withLoading(dismissBtn, Craft.t('lens', 'Dismissing...'), () => {
                return window.Lens.core.API.updateField(analysisId, field, sendValue).then((response) => {
                    if (response.data.success) {
                        this._fadeOutFlag(dismissBtn);
                        Craft.cp.displayNotice(Craft.t('lens', 'Flag cleared.'));
                    }
                });
            });
        },

        _handleRevert: function(e, revertBtn) {
            const analysisId = revertBtn.dataset.lensAnalysisId;
            const field = revertBtn.dataset.lensField;

            window.Lens.core.ButtonState.withLoading(revertBtn, Craft.t('lens', 'Restoring...'), () => {
                return window.Lens.core.API.revertField(analysisId, field).then((response) => {
                    if (response.data.success) {
                        Craft.cp.displayNotice(Craft.t('lens', 'AI flag restored. Refreshing...'));
                        setTimeout(() => window.location.reload(), window.Lens.config.ANIMATION.RELOAD_DELAY_MS);
                    }
                });
            });
        },

        // ================================================================
        // Helpers
        // ================================================================

        _fadeOutFlag: function(btn) {
            const detectionItem = btn.closest('.lens-detection-item');
            if (!detectionItem) return;

            detectionItem.style.opacity = '0.3';
            setTimeout(() => {
                detectionItem.remove();
            }, window.Lens.config.ANIMATION.FADE_MS);
        }
    };

    window.Lens.components.SafetyFlags = LensSafetyFlags;

    // Auto-initialize
    function init() {
        LensSafetyFlags.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
