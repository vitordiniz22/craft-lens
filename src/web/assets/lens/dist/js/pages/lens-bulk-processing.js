/**
 * Lens Plugin - Bulk Processing Page
 * Manages bulk analysis processing with status polling and progress tracking
 * Refactored from lens-bulk.js to use new utilities
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    const LensBulkProcessing = {
        container: null,
        currentState: 'ready',
        pollInterval: null,
        _initialized: false,

        init: function() {
            if (this._initialized) return;

            this.container = document.querySelector('[data-lens-target="bulk-container"]');
            if (!this.container) return;

            this.currentState = this.container.dataset.lensInitialState || 'ready';

            this._bindEvents();
            this._initialized = true;

            // If already processing, start polling
            if (this.currentState === 'processing') {
                this.startPolling();
                this.poll();
            }
        },

        _bindEvents: function() {
            const DOM = window.Lens.core.DOM;

            // Form submission
            const form = document.querySelector('[data-lens-target="bulk-form"]');
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.startProcessing(form);
                });
            }

            // Buttons
            DOM.delegate('[data-lens-action="bulk-retry"], [data-lens-action="bulk-retry-complete"]', 'click', this.retryFailed.bind(this));
            DOM.delegate('[data-lens-action="bulk-dismiss-complete"]', 'click', this._handleDismissComplete.bind(this));
            DOM.delegate('[data-lens-action="bulk-cancel"]', 'click', this.cancelProcessing.bind(this));

            // Volume select
            const select = window.Lens.core.DOM.findControl('volume-select');
            if (select) {
                select.addEventListener('change', this.updateVolumeStats.bind(this));
            }
        },

        _handleDismissComplete: function() {
            this.switchState('ready');
            this.checkStatus();
        },

        getSelectedVolumeId: function() {
            const select = window.Lens.core.DOM.findControl('volume-select');
            return select && select.value ? select.value : null;
        },

        updateVolumeStats: function() {
            const volumeId = this.getSelectedVolumeId();
            let url = 'lens/bulk/status';
            if (volumeId) url += '?volumeId=' + volumeId;

            window.Lens.core.API.get(url).then((response) => {
                this.updateStats(response.data.stats);

                if (response.data.estimatedCost !== undefined) {
                    const costEl = document.querySelector('.lens-estimated-cost strong');
                    if (costEl) costEl.textContent = '$' + response.data.estimatedCost.toFixed(4);
                }

                const startBtn = document.querySelector('[data-lens-action="bulk-start"]');
                if (startBtn) startBtn.disabled = response.data.stats.unprocessed === 0;
            }).catch(() => {
                // Error already logged and displayed by API wrapper
            });
        },

        cancelProcessing: function() {
            const btn = document.querySelector('[data-lens-action="bulk-cancel"]');
            if (!btn) return;

            window.Lens.core.ButtonState.withLoading(btn, Craft.t('lens', 'Cancelling...'), () => {
                return window.Lens.core.API.post('lens/bulk/cancel').then((response) => {
                    if (response.data.success) {
                        this.stopPolling();
                        this.switchState('ready');
                        Craft.cp.displayNotice(Craft.t('lens', 'Processing cancelled.'));
                        this.checkStatus();
                    }
                });
            });
        },

        startProcessing: function(form) {
            const btn = document.querySelector('[data-lens-action="bulk-start"]');

            window.Lens.core.ButtonState.withLoading(btn, Craft.t('lens', 'Starting...'), () => {
                return window.Lens.core.API.post('lens/bulk/process', Object.fromEntries(new FormData(form)))
                    .then((response) => {
                        if (response.data.success) {
                            this.switchState('processing');
                            this.startPolling();
                            this.poll();
                            Craft.cp.displayNotice(Craft.t('lens', 'Bulk processing started.'));
                        }
                    });
            });
        },

        retryFailed: function() {
            const btn = document.querySelector('[data-lens-action="bulk-retry"]') ||
                        document.querySelector('[data-lens-action="bulk-retry-complete"]');
            if (!btn) return;

            window.Lens.core.ButtonState.withLoading(btn, Craft.t('lens', 'Retrying...'), () => {
                return window.Lens.core.API.post('lens/bulk/retry-failed').then((response) => {
                    if (response.data.success) {
                        if (response.data.count > 0) {
                            this.switchState('processing');
                            this.startPolling();
                            this.poll();
                            Craft.cp.displayNotice(response.data.count + ' ' + Craft.t('lens', 'failed analyses queued for retry.'));
                        } else {
                            Craft.cp.displayNotice(Craft.t('lens', 'No failed analyses to retry.'));
                            throw new Error('No retry needed'); // Trigger finally to restore button
                        }
                    }
                });
            });
        },

        checkStatus: function() {
            const volumeId = this.getSelectedVolumeId();
            let url = 'lens/bulk/status';
            if (volumeId) url += '?volumeId=' + volumeId;

            window.Lens.core.API.get(url).then((response) => {
                this.updateUI(response.data);

                if (response.data.estimatedCost !== undefined) {
                    const costEl = document.querySelector('.lens-estimated-cost strong');
                    if (costEl) costEl.textContent = '$' + response.data.estimatedCost.toFixed(4);
                }

                if (response.data.state === 'processing' && !this.pollInterval) {
                    this.startPolling();
                }
            }).catch(() => {
                // Error already logged and displayed by API wrapper
            });
        },

        startPolling: function() {
            if (this.pollInterval) return;
            this.pollInterval = setInterval(() => this.poll(), 5000);
        },

        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        poll: function() {
            window.Lens.core.API.get('lens/bulk/status', {}, { showErrorNotice: false, logErrors: false })
                .then((response) => {
                    this.updateUI(response.data);
                    if (response.data.state !== 'processing') {
                        this.stopPolling();
                    }
                })
                .catch(() => {
                    // Polling errors are expected and silent (logged errors disabled)
                });
        },

        updateUI: function(data) {
            this.updateStats(data.stats);

            if (data.state !== this.currentState) {
                this.switchState(data.state);
                if (data.transition && data.transition.message) {
                    Craft.cp.displayNotice(data.transition.message);
                }
            }

            if (data.state === 'processing' && data.progress) {
                this.updateProgress(data.progress, data.session, data.queueInfo);
            }

            if (data.state === 'complete') {
                this.updateCompleteSummary(data.stats, data.session);
            }
        },

        updateStats: function(stats) {
            const elements = document.querySelectorAll('[data-lens-stat]');
            elements.forEach((el) => {
                const key = el.dataset.lensStat;
                if (stats[key] !== undefined) {
                    el.textContent = stats[key].toLocaleString();
                }
            });
        },

        updateProgress: function(progress, session, queueInfo) {
            const bar = document.querySelector('[data-lens-target="progress-bar"]');
            if (bar) bar.style.width = progress.percentComplete + '%';

            const text = document.querySelector('[data-lens-target="progress-text"]');
            if (text) {
                text.textContent = progress.completed.toLocaleString() + ' / ' +
                                 progress.total.toLocaleString() + ' (' +
                                 Math.round(progress.percentComplete) + '%)';
            }

            this._setElementText('completed-count', progress.completed);
            this._setElementText('failed-count', progress.failed);
            this._setElementText('remaining-count', progress.remaining);

            if (queueInfo && queueInfo.jobDescription) {
                this._setElementText('job-description', queueInfo.jobDescription);
            }

            if (session && session.actualCost !== undefined) {
                this._setElementText('current-cost', '$' + session.actualCost.toFixed(4));
            }
        },

        updateCompleteSummary: function(stats, session) {
            this._setElementText('summary-analyzed', stats.analyzed);
            this._setElementText('summary-failed', stats.failed);
            this._setElementText('summary-review', stats.pendingReview);

            if (session && session.actualCost !== undefined) {
                this._setElementText('summary-cost', '$' + session.actualCost.toFixed(4));
            }

            const retryBtn = document.querySelector('[data-lens-action="bulk-retry-complete"]');
            if (retryBtn) {
                retryBtn.style.display = stats.failed > 0 ? 'inline-block' : 'none';
            }
        },

        switchState: function(newState) {
            const states = ['ready', 'processing', 'complete'];
            states.forEach((state) => {
                const el = document.querySelector('[data-lens-target="state-' + state + '"]');
                if (el) el.style.display = (state === newState) ? 'block' : 'none';
            });
            this.currentState = newState;
        },

        _setElementText: function(targetName, value) {
            const el = document.querySelector('[data-lens-target="' + targetName + '"]');
            if (el) {
                el.textContent = typeof value === 'number' ? value.toLocaleString() : value;
            }
        }
    };

    window.Lens.pages.BulkProcessing = LensBulkProcessing;

    function init() {
        LensBulkProcessing.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
