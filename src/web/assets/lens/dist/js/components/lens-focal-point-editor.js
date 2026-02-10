/**
 * Lens Plugin - Focal Point Editor Component
 * Image focal point selection and zoom controls
 * Extracted and refactored from lens-review.js
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    /**
     * Focal Point Editor Component
     */
    const LensFocalPointEditor = {
        zoomLevel: 0,

        /**
         * Get zoom steps from config
         */
        get ZOOM_STEPS() {
            return window.Lens.config.ZOOM.STEPS;
        },

        /**
         * Initialize focal point editor
         */
        init: function() {
            if (!this._shouldInit()) return;
            this._bindEvents();
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
            this._bindZoomControls();
        },

        // ================================================================
        // Focal Point Selection
        // ================================================================

        _bindFocalPointClick: function() {
            const imgContainer = document.querySelector('[data-lens-target="image-container"]');
            if (!imgContainer) return;

            imgContainer.addEventListener('click', (e) => {
                const img = imgContainer.querySelector('img');
                if (!img) return;

                const rect = img.getBoundingClientRect();
                let x = (e.clientX - rect.left) / rect.width;
                let y = (e.clientY - rect.top) / rect.height;

                // Clamp to 0-1 range
                x = Math.max(0, Math.min(1, x));
                y = Math.max(0, Math.min(1, y));

                this._updateFocalPoint(x, y);
            });
        },

        _updateFocalPoint: function(x, y) {
            // Update hidden inputs
            const focalXInput = window.Lens.core.DOM.findControl('focal-x');
            const focalYInput = window.Lens.core.DOM.findControl('focal-y');

            if (focalXInput) focalXInput.value = x.toFixed(4);
            if (focalYInput) focalYInput.value = y.toFixed(4);

            // Update marker position
            const marker = document.querySelector('[data-lens-target="focal-marker"]');
            if (marker) {
                marker.style.left = (x * 100) + '%';
                marker.style.top = (y * 100) + '%';
                marker.style.display = 'block';
            }
        },

        // ================================================================
        // Zoom Controls
        // ================================================================

        _bindZoomControls: function() {
            const DOM = window.Lens.core.DOM;

            DOM.delegate('[data-lens-action="zoom-in"]', 'click', this._handleZoomIn.bind(this));
            DOM.delegate('[data-lens-action="zoom-out"]', 'click', this._handleZoomOut.bind(this));
            DOM.delegate('[data-lens-action="zoom-fit"]', 'click', this._handleZoomFit.bind(this));
        },

        _handleZoomIn: function() {
            const maxLevel = window.Lens.config.ZOOM.MAX_LEVEL;
            this.zoomLevel = Math.min(maxLevel, this.zoomLevel + 1);
            this._applyZoom();
        },

        _handleZoomOut: function() {
            const minLevel = window.Lens.config.ZOOM.DEFAULT_LEVEL;
            this.zoomLevel = Math.max(minLevel, this.zoomLevel - 1);
            this._applyZoom();
        },

        _handleZoomFit: function() {
            this.zoomLevel = window.Lens.config.ZOOM.DEFAULT_LEVEL;
            this._applyZoom();
        },

        _applyZoom: function() {
            const scale = this.ZOOM_STEPS[this.zoomLevel];
            const img = document.querySelector('[data-lens-target="review-image"]');
            const zoomLevelSpan = document.querySelector('[data-lens-target="zoom-level"]');

            if (img) {
                img.style.transform = scale === 1 ? '' : 'scale(' + scale + ')';
                img.style.transformOrigin = 'center center';
            }

            if (zoomLevelSpan) {
                zoomLevelSpan.textContent = Math.round(scale * 100) + '%';
            }
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
