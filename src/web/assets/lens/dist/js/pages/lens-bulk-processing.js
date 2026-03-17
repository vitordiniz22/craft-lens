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
            self._poll();
            self._scheduleNext();
        },

        _scheduleNext: function() {
            var self = this;
            var delay = window.Lens.config.ANIMATION.BULK_PROGRESS_POLL_MS;

            self._pollTimer = setTimeout(function() {
                self._poll();
                self._scheduleNext();
            }, delay);
        },

        _poll: function() {
            var self = this;

            window.Lens.core.API.fetchHtml('lens/bulk/progress', {
                showErrorNotice: false
            }).then(function(result) {
                var state = result.headers['x-lens-state'];

                if (state !== 'processing') {
                    self._stopPolling();
                    window.Lens.utils.safeReload();
                    return;
                }

                if (self._container && self._container.isConnected) {
                    self._container.innerHTML = result.html;
                }
            }).catch(function() {
                self._stopPolling();
                Craft.cp.displayError(Craft.t('lens', 'Failed to load progress. Please refresh the page.'));
            });
        },

        _stopPolling: function() {
            if (this._pollTimer) {
                clearTimeout(this._pollTimer);
                this._pollTimer = null;
            }
        },

        _bindCleanup: function() {
            var self = this;
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
