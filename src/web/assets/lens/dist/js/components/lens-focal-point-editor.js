/**
 * Lens Plugin - Focal Point Editor Component
 * Image focal point selection with live feedback
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Focal Point Editor Component
     */
    const LensFocalPointEditor = {
        _initialized: false,

        /**
         * Initialize focal point editor
         */
        init: function() {
            if (this._initialized) return;
            if (!this._shouldInit()) return;
            this._bindEvents();
            this._autoDismissHint();
            this._initialized = true;
        },

        /**
         * Check if focal point editor should initialize
         * @returns {boolean}
         * @private
         */
        _shouldInit: function() {
            return document.querySelector('[data-lens-target="image-container"]') !== null;
        },

        /**
         * Bind all event handlers
         * @private
         */
        _bindEvents: function() {
            this._bindFocalPointClick();
            this._bindFocalRevert();
        },

        // ================================================================
        // Focal Point Selection
        // ================================================================

        _bindFocalPointClick: function() {
            var imgContainer = document.querySelector('[data-lens-target="image-container"]');
            if (!imgContainer) return;

            imgContainer.addEventListener('click', (e) => {
                var img = imgContainer.querySelector('img');
                if (!img) return;

                var rect = img.getBoundingClientRect();
                var x = (e.clientX - rect.left) / rect.width;
                var y = (e.clientY - rect.top) / rect.height;

                // Clamp to 0-1 range
                x = Math.max(0, Math.min(1, x));
                y = Math.max(0, Math.min(1, y));

                this._updateFocalPoint(x, y);
            });
        },

        _updateFocalPoint: function(x, y) {
            // Update hidden inputs
            var focalXInput = window.Lens.core.DOM.findControl('focal-x');
            var focalYInput = window.Lens.core.DOM.findControl('focal-y');

            if (focalXInput) focalXInput.value = x.toFixed(4);
            if (focalYInput) focalYInput.value = y.toFixed(4);

            // Update marker position with pulse
            var marker = document.querySelector('[data-lens-target="focal-marker"]');
            if (marker) {
                marker.style.left = (x * 100) + '%';
                marker.style.top = (y * 100) + '%';
                marker.style.display = 'block';

                // Trigger pulse animation
                marker.classList.remove('lens-review-focal-marker--pulse');
                void marker.offsetWidth;
                marker.classList.add('lens-review-focal-marker--pulse');
            }

            var xPct = Math.round(x * 100);
            var yPct = Math.round(y * 100);

            // Update left-panel focal status text
            var statusText = document.querySelector('[data-lens-target="focal-status-text"]');
            if (statusText) {
                statusText.textContent = Craft.t('lens', 'Focal: {x}, {y}', {
                    x: xPct + '%',
                    y: yPct + '%'
                });
            }

            // Update right-panel quality section text
            var qualityFpText = document.querySelector('[data-lens-target="focal-display-text"]');
            if (qualityFpText) {
                qualityFpText.textContent = Craft.t('lens', 'X: {x}, Y: {y}', {
                    x: xPct + '%',
                    y: yPct + '%'
                });
            }

            // Show/hide "Modified" badge and AI suggestion row
            var container = document.querySelector('[data-lens-target="image-container"]');
            if (container) {
                var aiX = parseFloat(container.dataset.lensFocalXAi);
                var aiY = parseFloat(container.dataset.lensFocalYAi);
                var hasAi = !isNaN(aiX) && !isNaN(aiY);
                var isModified = hasAi && (Math.abs(x - aiX) > 0.005 || Math.abs(y - aiY) > 0.005);

                var badge = document.querySelector('[data-lens-target="focal-status-badge"]');
                if (badge) {
                    badge.style.display = isModified ? '' : 'none';
                }

                var aiSuggestion = document.querySelector('[data-lens-target="focal-ai-suggestion"]');
                if (aiSuggestion) {
                    aiSuggestion.style.display = isModified ? '' : 'none';
                }
            }

            // Dismiss hint on first interaction
            this._dismissHint();
        },

        // ================================================================
        // Hint Overlay
        // ================================================================

        _autoDismissHint: function() {
            setTimeout(function() {
                this._dismissHint();
            }.bind(this), 4000);
        },

        _dismissHint: function() {
            var hint = document.querySelector('[data-lens-target="focal-hint"]');
            if (hint && hint.style.opacity !== '0') {
                hint.style.opacity = '0';
                setTimeout(function() { hint.style.display = 'none'; }, 300);
            }
        },

        // ================================================================
        // Focal Revert
        // ================================================================

        _bindFocalRevert: function() {
            var DOM = window.Lens.core.DOM;
            DOM.delegate('[data-lens-action="focal-revert"]', 'click', function(e, btn) {
                var aiX = parseFloat(btn.dataset.lensFocalXAi);
                var aiY = parseFloat(btn.dataset.lensFocalYAi);
                if (isNaN(aiX) || isNaN(aiY)) return;
                this._updateFocalPoint(aiX, aiY);
            }.bind(this));
        }
    };

    window.Lens.components.FocalPointEditor = LensFocalPointEditor;

    // Auto-initialize
    function init() {
        LensFocalPointEditor.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
