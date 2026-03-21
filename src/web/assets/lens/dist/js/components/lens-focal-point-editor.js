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
    function isFocalPointMatch(x, y, refX, refY) {
        var epsilon = window.Lens.config.FOCAL_POINT.EPSILON;
        return !isNaN(refX) && !isNaN(refY) &&
            Math.abs(x - refX) < epsilon && Math.abs(y - refY) < epsilon;
    }

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
            this._bindFocalUseCurrent();
            this._bindFocalAccept();
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
                if (rect.width === 0 || rect.height === 0) return;

                var x = (e.clientX - rect.left) / rect.width;
                var y = (e.clientY - rect.top) / rect.height;

                // Clamp to 0-1 range
                x = Math.max(0, Math.min(1, x));
                y = Math.max(0, Math.min(1, y));

                // Clear accepted flag — manual click is a new decision
                delete imgContainer.dataset.lensFpAccepted;

                this._updateFocalPoint(x, y);
            });
        },

        /**
         * Orchestrator — delegates to focused helpers.
         */
        _updateFocalPoint: function(x, y) {
            this._updateFocalInputs(x, y);
            this._updateFocalMarker(x, y);
            this._updateFocalStatusText(x, y);

            var container = document.querySelector('[data-lens-target="image-container"]');
            var matches = this._updateFocalBadgeAndSuggestions(container, x, y);

            this._updateFocalPointState(container, matches.isAiMatch, matches.isAssetMatch);
            this._dismissHint();
        },

        _updateFocalInputs: function(x, y) {
            var focalXInput = window.Lens.core.DOM.findControl('focal-x');
            var focalYInput = window.Lens.core.DOM.findControl('focal-y');
            if (focalXInput) focalXInput.value = x.toFixed(4);
            if (focalYInput) focalYInput.value = y.toFixed(4);
        },

        _updateFocalMarker: function(x, y) {
            var marker = document.querySelector('[data-lens-target="focal-marker"]');
            if (!marker) return;
            marker.style.left = (x * 100) + '%';
            marker.style.top = (y * 100) + '%';
            window.Lens.core.DOM.show(marker);

            marker.classList.remove('lens-review-focal-marker--pulse');
            void marker.offsetWidth;
            marker.classList.add('lens-review-focal-marker--pulse');
        },

        _updateFocalStatusText: function(x, y) {
            var xPct = Math.round(x * 100);
            var yPct = Math.round(y * 100);

            var statusText = document.querySelector('[data-lens-target="focal-status-text"]');
            if (statusText) {
                statusText.textContent = Craft.t('lens', 'Focal: {x}, {y}', { x: xPct + '%', y: yPct + '%' });
            }

            var qualityFpText = document.querySelector('[data-lens-target="focal-display-text"]');
            if (qualityFpText) {
                qualityFpText.textContent = Craft.t('lens', 'X: {x}, Y: {y}', { x: xPct + '%', y: yPct + '%' });
            }
        },

        /**
         * Update source badge and AI suggestion rows based on coordinate matches.
         * @returns {{isAiMatch: boolean, isAssetMatch: boolean}}
         */
        _updateFocalBadgeAndSuggestions: function(container, x, y) {
            if (!container) return { isAiMatch: false, isAssetMatch: false };

            var DOM = window.Lens.core.DOM;

            var aiX = parseFloat(container.dataset.lensFocalXAi);
            var aiY = parseFloat(container.dataset.lensFocalYAi);
            var hasAi = !isNaN(aiX) && !isNaN(aiY);
            var isAiMatch = hasAi && isFocalPointMatch(x, y, aiX, aiY);

            var assetX = parseFloat(container.dataset.lensAssetFocalX);
            var assetY = parseFloat(container.dataset.lensAssetFocalY);
            var hasAsset = container.dataset.lensAssetHasFocalPoint === '1'
                && !isNaN(assetX) && !isNaN(assetY);
            var isAssetMatch = hasAsset && isFocalPointMatch(x, y, assetX, assetY);

            // Source badge: AI (violet), Edited (amber), hidden when matches asset
            var badge = document.querySelector('[data-lens-target="focal-status-badge"]');
            if (badge) {
                if (isAssetMatch) {
                    DOM.hide(badge);
                } else if (isAiMatch) {
                    badge.textContent = Craft.t('lens', 'AI');
                    badge.className = 'lens-review-focal-badge';
                    DOM.show(badge);
                } else {
                    badge.textContent = Craft.t('lens', 'Edited');
                    badge.className = 'lens-review-focal-badge lens-review-focal-badge--edited';
                    DOM.show(badge);
                }
            }

            // AI suggestion revert rows
            var aiSuggestion = document.querySelector('[data-lens-target="focal-ai-suggestion"]');
            if (aiSuggestion) DOM.toggle(aiSuggestion, !isAiMatch && hasAi);
            var fpAiRevert = document.querySelector('[data-lens-target="fp-ai-revert"]');
            if (fpAiRevert) DOM.toggle(fpAiRevert, !isAiMatch && hasAi);

            return { isAiMatch: isAiMatch, isAssetMatch: isAssetMatch };
        },

        /**
         * Update marker style, reference indicator, and action bars based on
         * whether the focal point matches the asset's existing one.
         *
         * @param {Element|null} container - The image container element
         * @param {boolean} isAiMatch - Whether current FP matches the AI suggestion
         * @param {boolean} isAssetMatch - Whether current FP matches the asset's existing FP
         * @private
         */
        _updateFocalPointState: function(container, isAiMatch, isAssetMatch) {
            if (!container || container.dataset.lensAssetHasFocalPoint !== '1') return;

            var accepted = container.dataset.lensFpAccepted === '1';
            var shouldHide = isAssetMatch || accepted;

            // Toggle active marker style (red vs Craft-native)
            var marker = document.querySelector('[data-lens-target="focal-marker"]');
            if (marker) {
                marker.classList.toggle('lens-review-focal-marker--current', isAssetMatch);
            }

            // Show/hide static reference marker
            var DOM = window.Lens.core.DOM;
            var ref = document.querySelector('[data-lens-target="focal-ref"]');
            if (ref) {
                DOM.toggle(ref, !shouldHide);
            }

            var contextText = isAiMatch
                ? Craft.t('lens', 'Different focal point suggested')
                : Craft.t('lens', 'Replaces asset focal point');

            // Show/hide left-panel action bar + update text + color
            var actions = document.querySelector('[data-lens-target="fp-actions"]');
            var actionsText = document.querySelector('[data-lens-target="fp-actions-text"]');
            if (actions) {
                DOM.toggle(actions, !shouldHide);
                actions.classList.toggle('lens-ai-suggestion-inline--warning', !isAiMatch);
            }
            if (actionsText && !shouldHide) {
                actionsText.textContent = contextText;
            }

            // Show/hide right-panel conflict bar + update text + color
            var conflict = document.querySelector('[data-lens-target="focal-asset-conflict"]');
            var conflictText = document.querySelector('[data-lens-target="focal-asset-conflict-text"]');
            if (conflict) {
                DOM.toggle(conflict, !shouldHide);
                conflict.classList.toggle('lens-ai-suggestion-inline--warning', !isAiMatch);
            }
            if (conflictText && !shouldHide) {
                conflictText.textContent = contextText;
            }
        },

        // ================================================================
        // Hint Overlay
        // ================================================================

        _autoDismissHint: function() {
            setTimeout(function() {
                this._dismissHint();
            }.bind(this), window.Lens.config.ANIMATION.HINT_AUTO_DISMISS_MS);
        },

        _dismissHint: function() {
            var hint = document.querySelector('[data-lens-target="focal-hint"]');
            if (hint && hint.style.opacity !== '0') {
                hint.style.opacity = '0';
                setTimeout(function() {
                    window.Lens.core.DOM.hide(hint);
                }, window.Lens.config.ANIMATION.HINT_FADE_MS);
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
        },

        /**
         * Bind "Accept" button — confirms the current focal point so it
         * gets submitted on approve (overrides asset FP).
         * Reads from the hidden inputs (always up-to-date from _updateFocalPoint).
         * @private
         */
        _bindFocalAccept: function() {
            var DOM = window.Lens.core.DOM;
            DOM.delegate('[data-lens-action="focal-accept"]', 'click', function() {
                var container = document.querySelector('[data-lens-target="image-container"]');
                if (!container) return;

                var focalXInput = window.Lens.core.DOM.findControl('focal-x');
                var focalYInput = window.Lens.core.DOM.findControl('focal-y');
                if (!focalXInput || !focalYInput) return;

                var x = parseFloat(focalXInput.value);
                var y = parseFloat(focalYInput.value);
                if (isNaN(x) || isNaN(y)) return;

                // Mark as accepted so the action bar + ref stay hidden
                container.dataset.lensFpAccepted = '1';
                this._updateFocalPoint(x, y);
            }.bind(this));
        },

        /**
         * Bind "Undo" button to restore the asset's existing focal point
         * @private
         */
        _bindFocalUseCurrent: function() {
            var DOM = window.Lens.core.DOM;
            DOM.delegate('[data-lens-action="focal-use-current"]', 'click', function(e, btn) {
                var assetX = parseFloat(btn.dataset.lensAssetFocalX);
                var assetY = parseFloat(btn.dataset.lensAssetFocalY);
                if (isNaN(assetX) || isNaN(assetY)) return;
                this._updateFocalPoint(assetX, assetY);
            }.bind(this));
        }
    };

    window.Lens.components.FocalPointEditor = LensFocalPointEditor;

    // Auto-initialize
    Lens.utils.onReady(function() {
        LensFocalPointEditor.init();
    });
})();
