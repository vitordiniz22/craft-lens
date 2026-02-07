/**
 * Lens Plugin - Bulk Processing
 * Manages bulk analysis processing with status polling and progress tracking
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    // ==========================================================================
    // Bulk Processing Page
    // ==========================================================================

    var LensBulkProcessing = {
        container: null,
        currentState: 'ready',
        pollInterval: null,

        init: function() {
            this.container = document.getElementById('lens-bulk-container');
            if (!this.container) return;

            this.currentState = this.container.dataset.initialState || 'ready';

            this.bindFormHandler();
            this.bindRetryButton();
            this.bindDismissButton();
            this.bindVolumeChange();
            this.bindCancelButton();

            // If already processing, start polling
            if (this.currentState === 'processing') {
                this.startPolling();
                this.poll();
            }
        },

        getSelectedVolumeId: function() {
            var select = document.getElementById('volumeId');
            return select && select.value ? select.value : null;
        },

        bindFormHandler: function() {
            var self = this;
            var form = document.getElementById('lens-process-form');
            if (!form) return;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                self.startProcessing(form);
            });
        },

        bindRetryButton: function() {
            var self = this;
            var btn = document.getElementById('lens-retry-btn');
            if (btn) {
                btn.addEventListener('click', function() {
                    self.retryFailed();
                });
            }

            var completeBtn = document.getElementById('lens-retry-complete-btn');
            if (completeBtn) {
                completeBtn.addEventListener('click', function() {
                    self.retryFailed();
                });
            }
        },

        bindDismissButton: function() {
            var self = this;
            var btn = document.getElementById('lens-dismiss-complete');
            if (!btn) return;

            btn.addEventListener('click', function() {
                self.switchState('ready');
                self.checkStatus();
            });
        },

        bindVolumeChange: function() {
            var self = this;
            var select = document.getElementById('volumeId');
            if (!select) return;

            select.addEventListener('change', function() {
                self.updateVolumeStats();
            });
        },

        bindCancelButton: function() {
            var self = this;
            var btn = document.getElementById('lens-cancel-btn');
            if (!btn) return;

            btn.addEventListener('click', function() {
                self.cancelProcessing();
            });
        },

        updateVolumeStats: function() {
            var self = this;
            var volumeId = this.getSelectedVolumeId();
            var url = 'lens/bulk/status';
            if (volumeId) {
                url += '?volumeId=' + volumeId;
            }

            Craft.sendActionRequest('GET', url, {})
                .then(function(response) {
                    // Update stats display
                    self.updateStats(response.data.stats);

                    // Update estimated cost
                    if (response.data.estimatedCost !== undefined) {
                        var costEl = document.querySelector('.lens-estimated-cost strong');
                        if (costEl) {
                            costEl.textContent = '$' + response.data.estimatedCost.toFixed(4);
                        }
                    }

                    // Update start button state
                    var startBtn = document.getElementById('lens-start-btn');
                    if (startBtn) {
                        startBtn.disabled = response.data.stats.unprocessed === 0;
                    }
                })
                .catch(function(error) {
                    console.error('[Lens] Error fetching volume stats:', error);
                });
        },

        cancelProcessing: function() {
            var self = this;
            var btn = document.getElementById('lens-cancel-btn');
            if (!btn) return;

            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = Craft.t('lens', 'Cancelling...');

            Craft.sendActionRequest('POST', 'lens/bulk/cancel', {})
                .then(function(response) {
                    if (response.data.success) {
                        self.stopPolling();
                        self.switchState('ready');
                        Craft.cp.displayNotice(Craft.t('lens', 'Processing cancelled.'));
                        self.checkStatus();
                    }
                })
                .catch(function(error) {
                    console.error('[Lens] Error cancelling:', error);
                    Craft.cp.displayError(Craft.t('lens', 'Failed to cancel processing.'));
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
        },

        startProcessing: function(form) {
            var self = this;
            var btn = document.getElementById('lens-start-btn');
            var originalText = btn.textContent;

            btn.disabled = true;
            btn.textContent = Craft.t('lens', 'Starting...');

            Craft.sendActionRequest('POST', 'lens/bulk/process', { data: Object.fromEntries(new FormData(form)) })
                .then(function(response) {
                    if (response.data.success) {
                        self.switchState('processing');
                        self.startPolling();
                        self.poll();
                        Craft.cp.displayNotice(Craft.t('lens', 'Bulk processing started.'));
                    }
                })
                .catch(function(error) {
                    console.error('[Lens] Error starting processing:', error);
                    Craft.cp.displayError(Craft.t('lens', 'Failed to start processing.'));
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
        },

        retryFailed: function() {
            var self = this;
            var btn = document.getElementById('lens-retry-btn') || document.getElementById('lens-retry-complete-btn');
            if (!btn) return;

            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = Craft.t('lens', 'Retrying...');

            Craft.sendActionRequest('POST', 'lens/bulk/retry-failed', {})
                .then(function(response) {
                    if (response.data.success) {
                        if (response.data.count > 0) {
                            self.switchState('processing');
                            self.startPolling();
                            self.poll();
                            Craft.cp.displayNotice(response.data.count + ' ' + Craft.t('lens', 'failed analyses queued for retry.'));
                        } else {
                            Craft.cp.displayNotice(Craft.t('lens', 'No failed analyses to retry.'));
                            btn.disabled = false;
                            btn.textContent = originalText;
                        }
                    }
                })
                .catch(function() {
                    Craft.cp.displayError(Craft.t('lens', 'Failed to retry.'));
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
        },

        checkStatus: function() {
            var self = this;
            var volumeId = this.getSelectedVolumeId();
            var url = 'lens/bulk/status';
            if (volumeId) {
                url += '?volumeId=' + volumeId;
            }

            Craft.sendActionRequest('GET', url, {})
                .then(function(response) {
                    self.updateUI(response.data);

                    // Update estimated cost
                    if (response.data.estimatedCost !== undefined) {
                        var costEl = document.querySelector('.lens-estimated-cost strong');
                        if (costEl) {
                            costEl.textContent = '$' + response.data.estimatedCost.toFixed(4);
                        }
                    }

                    if (response.data.state === 'processing' && !self.pollInterval) {
                        self.startPolling();
                    }
                })
                .catch(function(error) {
                    console.error('[Lens] Error checking status:', error);
                });
        },

        startPolling: function() {
            var self = this;
            if (this.pollInterval) return;

            this.pollInterval = setInterval(function() {
                self.poll();
            }, 5000);
        },

        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        poll: function() {
            var self = this;
            Craft.sendActionRequest('GET', 'lens/bulk/status', {})
                .then(function(response) {
                    self.updateUI(response.data);

                    if (response.data.state !== 'processing') {
                        self.stopPolling();
                    }
                })
                .catch(function(error) {
                    console.error('[Lens] Polling error:', error);
                });
        },

        updateUI: function(data) {
            // Update stats
            this.updateStats(data.stats);

            // Handle state transition
            if (data.state !== this.currentState) {
                this.switchState(data.state);

                if (data.transition && data.transition.message) {
                    Craft.cp.displayNotice(data.transition.message);
                }
            }

            // Update progress if processing
            if (data.state === 'processing' && data.progress) {
                this.updateProgress(data.progress, data.session, data.queueInfo);
            }

            // Update complete summary if complete
            if (data.state === 'complete') {
                this.updateCompleteSummary(data.stats, data.session);
            }
        },

        updateStats: function(stats) {
            var elements = document.querySelectorAll('[data-stat]');
            elements.forEach(function(el) {
                var key = el.dataset.stat;
                if (stats[key] !== undefined) {
                    el.textContent = stats[key].toLocaleString();
                }
            });
        },

        updateProgress: function(progress, session, queueInfo) {
            // Progress bar
            var bar = document.getElementById('lens-progress-bar');
            if (bar) {
                bar.style.width = progress.percentComplete + '%';
            }

            // Progress text
            var text = document.getElementById('lens-progress-text');
            if (text) {
                text.textContent = progress.completed.toLocaleString() + ' / ' +
                                   progress.total.toLocaleString() + ' (' +
                                   Math.round(progress.percentComplete) + '%)';
            }

            // Counters
            this.setElementText('lens-completed-count', progress.completed);
            this.setElementText('lens-failed-count', progress.failed);
            this.setElementText('lens-remaining-count', progress.remaining);

            // Job description
            if (queueInfo && queueInfo.jobDescription) {
                this.setElementText('lens-job-description', queueInfo.jobDescription);
            }

            // Current cost
            if (session && session.actualCost !== undefined) {
                this.setElementText('lens-current-cost', '$' + session.actualCost.toFixed(4));
            }
        },

        updateCompleteSummary: function(stats, session) {
            this.setElementText('lens-summary-analyzed', stats.analyzed);
            this.setElementText('lens-summary-failed', stats.failed);
            this.setElementText('lens-summary-review', stats.pendingReview);

            if (session && session.actualCost !== undefined) {
                this.setElementText('lens-summary-cost', '$' + session.actualCost.toFixed(4));
            }

            // Show/hide retry button based on failed count
            var retryBtn = document.getElementById('lens-retry-complete-btn');
            if (retryBtn) {
                retryBtn.style.display = stats.failed > 0 ? 'inline-block' : 'none';
            }
        },

        switchState: function(newState) {
            var states = ['ready', 'processing', 'complete'];

            states.forEach(function(state) {
                var el = document.getElementById('lens-state-' + state);
                if (el) {
                    el.style.display = (state === newState) ? 'block' : 'none';
                }
            });

            this.currentState = newState;
        },

        setElementText: function(id, value) {
            var el = document.getElementById(id);
            if (el) {
                el.textContent = typeof value === 'number' ? value.toLocaleString() : value;
            }
        }
    };

    window.Lens.BulkProcessing = LensBulkProcessing;

    // ==========================================================================
    // Initialize when DOM is ready
    // ==========================================================================

    function init() {
        LensBulkProcessing.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
