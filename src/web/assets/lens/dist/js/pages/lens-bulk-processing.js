/**
 * Lens Plugin - Bulk Processing Progress Polling
 * Only active during the processing state — polls for progress HTML updates.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    var LensBulkProcessing = {
        _initialized: false,
        _pollTimer: null,
        _container: null,

        init: function() {
            if (this._initialized) return;

            this._container = document.querySelector('[data-lens-target="progress-container"]');
            if (!this._container) return;

            this._startPolling();
            this._bindCleanup();
            this._initialized = true;
        },

        _startPolling: function() {
            var self = this;
            var intervalMs = window.Lens.config.ANIMATION.BULK_PROGRESS_POLL_MS;

            self._poll();
            self._pollTimer = setInterval(function() {
                self._poll();
            }, intervalMs);
        },

        _poll: function() {
            var self = this;

            window.Lens.core.API.fetchHtml('lens/bulk/progress', {
                showErrorNotice: false
            }).then(function(result) {
                var state = result.headers['x-lens-state'];
                var statsJson = result.headers['x-lens-stats'];

                if (state !== 'processing') {
                    self._stopPolling();
                    window.Lens.utils.safeReload();
                    return;
                }

                if (self._container && self._container.isConnected) {
                    self._container.innerHTML = result.html;
                }
                self._updateStatCards(statsJson);
            }).catch(function() {
                self._stopPolling();
                Craft.cp.displayError(Craft.t('lens', 'Failed to load progress. Please refresh the page.'));
            });
        },

        _updateStatCards: function(statsJson) {
            if (!statsJson) return;
            try {
                var stats = JSON.parse(statsJson);
            } catch (e) {
                return;
            }

            var cards = document.querySelectorAll('[data-lens-stat]');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var key = card.dataset.lensStat;
                if (stats[key] === undefined) continue;

                var value = stats[key];
                card.dataset.lensCount = String(value);
                var valueEl = card.querySelector('[data-lens-target="stat-value"]');
                if (valueEl) {
                    valueEl.textContent = Number(value).toLocaleString();
                }
            }
        },

        _stopPolling: function() {
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
        },

        _bindCleanup: function() {
            var self = this;
            // Clean up polling on page unload (Craft SPA navigation or browser close)
            window.addEventListener('beforeunload', function() {
                self._stopPolling();
            });
        }
    };

    window.Lens.pages.BulkProcessing = LensBulkProcessing;

    Lens.utils.onReady(function() {
        LensBulkProcessing.init();
    });
})();
