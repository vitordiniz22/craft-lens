/**
 * Lens Plugin - Asset Processing Service
 * Centralized asset analysis polling and status checking
 * Extracts polling logic from lens-asset-actions.js with improvements
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.services = window.Lens.services || {};

    /**
     * Asset Processing Service
     * Handles analysis status polling with exponential backoff
     */
    window.Lens.services.AssetProcessing = {
        /**
         * Active polling instances (for cleanup)
         * @private
         */
        _activePolls: {},

        /**
         * Poll for analysis completion with exponential backoff
         * Uses Page Visibility API to pause when tab is hidden
         * @param {string|number} assetId - Asset ID to poll
         * @param {Object} [options] - Polling options
         * @param {Function} [options.onComplete] - Called when analysis completes
         * @param {Function} [options.onError] - Called when analysis fails
         * @param {Function} [options.onProgress] - Called on each status check
         * @param {Function} [options.onMaxAttempts] - Called when max attempts reached
         * @param {boolean} [options.autoReload=true] - Auto reload page on completion
         * @param {number} [options.reloadDelay=1000] - Delay before reload (ms)
         * @returns {Object} Polling controller with stop() method
         */
        poll: function(assetId, options) {
            options = options || {};
            const self = this;

            // Get config values
            const intervals = window.Lens.config.POLLING.INTERVALS;
            const maxAttempts = window.Lens.config.POLLING.MAX_ATTEMPTS;

            let attempts = 0;
            let timeoutId = null;
            let stopped = false;

            const checkStatus = function() {
                // Stop if polling was cancelled
                if (stopped) return;

                // Pause if page is hidden (Page Visibility API)
                if (document.hidden) {
                    scheduleNext();
                    return;
                }

                attempts++;

                // Call progress callback
                if (options.onProgress) {
                    options.onProgress(attempts, maxAttempts);
                }

                Craft.sendActionRequest('GET', 'lens/analysis/get-status', {
                    params: { assetId: assetId }
                }).then(function(response) {
                    if (stopped) return;

                    const status = response.data.status;

                    // Handle terminal states
                    if (status === 'completed' || status === 'approved' || status === 'failed') {
                        self._clearPoll(assetId);

                        if (status === 'failed' && options.onError) {
                            options.onError(response.data);
                        } else if (options.onComplete) {
                            options.onComplete(response.data);
                        }

                        // Auto reload if requested
                        if (options.autoReload !== false) {
                            const message = status === 'failed'
                                ? Craft.t('lens', 'Analysis failed. Refreshing to show error...')
                                : Craft.t('lens', 'Analysis complete. Refreshing...');
                            Craft.cp.displayNotice(message);

                            const delay = options.reloadDelay || window.Lens.config.ANIMATION.RELOAD_DELAY_MS;
                            setTimeout(function() {
                                window.location.reload();
                            }, delay);
                        }

                    // Handle pending states
                    } else if (attempts < maxAttempts && self._isPendingStatus(status)) {
                        scheduleNext();

                    // Max attempts reached
                    } else if (attempts >= maxAttempts) {
                        self._clearPoll(assetId);

                        if (options.onMaxAttempts) {
                            options.onMaxAttempts(attempts);
                        } else {
                            Craft.cp.displayNotice(
                                Craft.t('lens', 'Analysis is taking longer than expected. Please refresh manually.')
                            );
                        }
                    }
                }).catch(function(err) {
                    if (stopped) return;

                    console.error('Lens: Failed to check analysis status', err);

                    // Retry a few times on network errors
                    if (attempts < 3) {
                        scheduleNext();
                    } else {
                        self._clearPoll(assetId);

                        const errorMsg = (err.response && err.response.data && err.response.data.error)
                            ? err.response.data.error
                            : 'Failed to check analysis status. Please refresh manually.';

                        Craft.cp.displayError(Craft.t('lens', errorMsg));

                        if (options.onError) {
                            options.onError(err);
                        }
                    }
                });
            };

            const scheduleNext = function() {
                if (stopped) return;

                // Exponential backoff - use interval based on attempt count
                const intervalIndex = Math.min(attempts, intervals.length - 1);
                const interval = intervals[intervalIndex];

                timeoutId = setTimeout(checkStatus, interval);
            };

            // Start polling
            timeoutId = setTimeout(checkStatus, intervals[0]);

            // Store active poll
            this._activePolls[assetId] = {
                timeoutId: timeoutId,
                stopped: false
            };

            // Return controller
            return {
                stop: function() {
                    stopped = true;
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                    self._clearPoll(assetId);
                }
            };
        },

        /**
         * Check if status is a pending/processing state
         * @param {string} status - Status to check
         * @returns {boolean} True if pending
         * @private
         */
        _isPendingStatus: function(status) {
            const pendingStates = ['not_found', 'pending', 'processing', 'pending_review'];
            return pendingStates.indexOf(status) !== -1;
        },

        /**
         * Clear active poll
         * @param {string|number} assetId - Asset ID
         * @private
         */
        _clearPoll: function(assetId) {
            if (this._activePolls[assetId]) {
                const poll = this._activePolls[assetId];
                if (poll.timeoutId) {
                    clearTimeout(poll.timeoutId);
                }
                delete this._activePolls[assetId];
            }
        },

        /**
         * Stop polling for a specific asset
         * @param {string|number} assetId - Asset ID
         */
        stopPolling: function(assetId) {
            this._clearPoll(assetId);
        },

        /**
         * Stop all active polls
         */
        stopAllPolling: function() {
            const assetIds = Object.keys(this._activePolls);
            assetIds.forEach(function(assetId) {
                this.stopPolling(assetId);
            }, this);
        },

        /**
         * Check if currently polling for an asset
         * @param {string|number} assetId - Asset ID
         * @returns {boolean} True if polling
         */
        isPolling: function(assetId) {
            return !!this._activePolls[assetId];
        }
    };
})();
