/**
 * Lens Plugin - Analysis Panel Page Controller
 * Orchestrates components and handles page-specific functionality
 * Replaces lens-asset-actions.js with cleaner, component-based architecture
 */
(function () {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.pages = window.Lens.pages || {};

    /**
     * Analysis Panel Page Controller
     * Coordinates InlineEditor, TagEditor, and ColorEditor components
     */
    const LensAnalysisPanel = {
        _initialized: false,

        /**
         * Initialize analysis panel
         */
        init: function () {
            if (this._initialized) return;
            if (!this._shouldInit()) return;

            // Components auto-initialize themselves, we just coordinate page-level features
            this.initAssetActions();
            this.initAutoPolling();
            this._initialized = true;
        },

        /**
         * Check if analysis panel should initialize
         * @returns {boolean}
         * @private
         */
        _shouldInit: function () {
            return document.querySelector('[data-lens-target="analysis-panel"]') !== null;
        },

        _cloneAppliedIndicator: function (text) {
            var tpl = document.querySelector('[data-lens-target="applied-template"]');
            if (!tpl) return null;
            var el = tpl.content.cloneNode(true);
            if (text) {
                var label = el.querySelector('[data-lens-target="applied-label"]');
                if (label) label.textContent = text;
            }
            return el;
        },

        // ================================================================
        // Asset Actions
        // ================================================================

        initAssetActions: function () {
            window.Lens.core.DOM.delegate(
                '[data-lens-action="analyze"], [data-lens-action="reprocess"]',
                'click',
                this._handleAnalyze.bind(this),
            );
            window.Lens.core.DOM.delegate(
                '[data-lens-action="apply-title"]',
                'click',
                this._handleApplyTitle.bind(this),
            );
            window.Lens.core.DOM.delegate(
                '[data-lens-action="apply-alt"]',
                'click',
                this._handleApplyAlt.bind(this),
            );
            window.Lens.core.DOM.delegate(
                '[data-lens-action="apply-focal-point"]',
                'click',
                this._handleApplyFocalPoint.bind(this),
            );
            // Alt proxy field handlers
            window.Lens.core.DOM.delegate(
                '[data-lens-action="alt-proxy-edit"]',
                'click',
                this._handleAltProxyEdit.bind(this),
            );
            window.Lens.core.DOM.delegate(
                '[data-lens-action="alt-proxy-save"]',
                'click',
                this._handleAltProxySave.bind(this),
            );
            window.Lens.core.DOM.delegate(
                '[data-lens-action="alt-proxy-cancel"]',
                'click',
                this._handleAltProxyCancel.bind(this),
            );
        },

        _handleAnalyze: function (e, btn) {
            if (btn.disabled) return;

            if (btn.dataset.lensAction === 'reprocess') {
                if (!confirm(Craft.t('lens', 'Re-analyze this image?\n\nAI-generated suggestions will be replaced with fresh results. Fields you\'ve manually edited will be preserved.'))) {
                    return;
                }
            }

            const assetId = btn.dataset.lensAssetId;
            const loadingText = btn.dataset.lensLoadingText;
            var self = this;

            var restoreBtn = window.Lens.core.ButtonState.setLoading(
                btn,
                loadingText,
                { labelSelector: '[data-lens-target="button-label"]', loadingClass: 'loading' },
            );

            window.Lens.core.API.post('lens/analysis/reprocess', {
                assetId: assetId,
            })
                .then(function (response) {
                    if (response.data.success) {
                        Craft.cp.displayNotice(
                            Craft.t('lens', 'Asset queued for analysis.'),
                        );

                        Craft.cp.runQueue();
                        self._enterProcessingState();

                        window.Lens.services.AssetProcessing.poll(assetId, {
                            onError: function() {
                                restoreBtn();
                                Craft.cp.displayError(Craft.t('lens', 'Analysis failed. Please try again.'));
                            },
                            onMaxAttempts: function() {
                                restoreBtn();
                                Craft.cp.displayError(Craft.t('lens', 'Analysis is taking longer than expected. Please refresh the page.'));
                            },
                        });
                    }
                })
                .catch(function() {
                    restoreBtn();
                    Craft.cp.displayError(Craft.t('lens', 'Failed to start analysis. Please try again.'));
                });
        },

        /**
         * Transition the analysis panel to a locked processing state.
         * Called after the reprocess API call succeeds, shows the processing
         * banner, hides edit triggers, and dims section content until the
         * page auto-reloads on completion.
         */
        _enterProcessingState: function () {
            const panel = document.querySelector('[data-lens-target="analysis-panel"]');

            if (!panel) return;

            const banner = panel.querySelector('[data-lens-target="processing-banner"]');
            const reprocessBtn = panel.querySelector('[data-lens-action="reprocess"]');
            const emptyState = panel.querySelector('[data-lens-target="empty-analysis"]');
            const statusBadge = panel.querySelector('[data-lens-target="status-badge"]');
            const actionsContainer = panel.querySelector('[data-lens-target="header-actions"]');

            panel.dataset.lensAnalysisStatus = window.Lens.config.STATUS.PROCESSING;
            panel.classList.add('lens-analysis-panel--processing');

            if (banner) {
                banner.removeAttribute('hidden');
            }

            if (reprocessBtn) {
                reprocessBtn.style.display = 'none';
            }

            if (emptyState) {
                emptyState.style.display = 'none';
            }

            if (statusBadge) {
                statusBadge.innerHTML =
                    '<span class="status pending"></span>' +
                    Craft.t('lens', 'Processing');
            } else if (actionsContainer) {
                const badge = document.createElement('div');
                badge.className = 'flex items-center gap-2xs';
                badge.dataset.lensTarget = 'status-badge';
                badge.innerHTML =
                    '<span class="status pending"></span>' +
                    Craft.t('lens', 'Processing');
                actionsContainer.insertBefore(badge, actionsContainer.firstChild);
            }
        },

        _handleApplyTitle: function (e, btn) {
            if (btn.disabled) return;

            const assetId = btn.dataset.lensAssetId;
            const analysisId = btn.dataset.lensAnalysisId;
            const siteId = btn.dataset.lensSiteId || null;

            window.Lens.core.ButtonState.withLoading(
                btn,
                Craft.t('lens', 'Applying'),
                () => {
                    return window.Lens.core.API.applyTitle(
                        assetId,
                        analysisId,
                        { siteId: siteId },
                    ).then((response) => {
                        if (response.data.success) {
                            Craft.cp.displayNotice(
                                Craft.t('lens', 'Title applied to asset.'),
                            );
                            this._updateBridgeAfterApply(btn, response.data.title);
                            this._syncNativeField('title', response.data.title);
                        }
                    });
                },
            );
        },

        _handleApplyAlt: function (e, btn) {
            if (btn.disabled) return;

            const assetId = btn.dataset.lensAssetId;
            const analysisId = btn.dataset.lensAnalysisId;
            const siteId = btn.dataset.lensSiteId || null;

            window.Lens.core.ButtonState.withLoading(
                btn,
                Craft.t('lens', 'Applying'),
                () => {
                    return window.Lens.core.API.applyAlt(
                        assetId,
                        analysisId,
                        { siteId: siteId },
                    ).then((response) => {
                        if (response.data.success) {
                            Craft.cp.displayNotice(
                                Craft.t('lens', 'Alt text applied to asset.'),
                            );
                            // Button lives in either a bridge or proxy — each method
                            // self-selects via .closest() and no-ops if not found
                            this._updateBridgeAfterApply(btn, response.data.alt);
                            this._updateProxyDisplayAfterApply(btn, response.data.alt);
                            this._syncNativeField('alt', response.data.alt);
                        }
                    });
                },
            );
        },

        /**
         * Update the native-field-bridge UI after a successful apply
         */
        _updateBridgeAfterApply: function (btn, appliedValue) {
            var bridge = btn.closest('[data-lens-target="native-bridge"]');
            if (!bridge) return;

            // Swap the apply link for an Applied indicator inside the purple bar
            var applyLink = bridge.querySelector('[data-lens-target="native-apply-link"]');
            if (applyLink) {
                var applied = this._cloneAppliedIndicator();
                if (applied) applyLink.replaceWith(applied);
            }
        },


        /**
         * Update the alt proxy field display after apply
         */
        _updateProxyDisplayAfterApply: function (btn, appliedValue) {
            var proxy = btn.closest('[data-lens-target="alt-proxy-field"]');
            if (!proxy) return;

            // Update textarea value
            var input = proxy.querySelector('[data-lens-target="alt-proxy-input"]');
            if (input) {
                input.value = appliedValue;
            }

            // Hide current value display (values now match)
            var currentDisplay = proxy.querySelector('[data-lens-target="alt-proxy-current"]');
            if (currentDisplay) window.Lens.core.DOM.hide(currentDisplay);

            // Swap the apply link for an Applied indicator (same as native-field-bridge)
            var applyLink = proxy.querySelector('[data-lens-target="alt-proxy-apply-link"]');
            if (applyLink) {
                var applied = this._cloneAppliedIndicator();
                if (applied) applyLink.replaceWith(applied);
            }
        },

        // ================================================================
        // Alt Proxy Field (editable alt when AltField not in layout)
        // ================================================================

        _handleAltProxyEdit: function (e, btn) {
            var proxy = btn.closest('[data-lens-target="alt-proxy-field"]');
            if (!proxy) return;

            var currentDisplay = proxy.querySelector('[data-lens-target="alt-proxy-current"]');
            var suggestionRow = proxy.querySelector('[data-lens-target="alt-proxy-suggestion-row"]');
            var editArea = proxy.querySelector('[data-lens-target="alt-proxy-edit-area"]');
            if (currentDisplay) window.Lens.core.DOM.hide(currentDisplay);
            if (suggestionRow) window.Lens.core.DOM.hide(suggestionRow);
            if (editArea) window.Lens.core.DOM.show(editArea);
        },

        _handleAltProxyCancel: function (e, btn) {
            var proxy = btn.closest('[data-lens-target="alt-proxy-field"]');
            if (!proxy) return;

            var currentDisplay = proxy.querySelector('[data-lens-target="alt-proxy-current"]');
            var suggestionRow = proxy.querySelector('[data-lens-target="alt-proxy-suggestion-row"]');
            var editArea = proxy.querySelector('[data-lens-target="alt-proxy-edit-area"]');
            if (editArea) window.Lens.core.DOM.hide(editArea);
            if (currentDisplay) window.Lens.core.DOM.show(currentDisplay);
            if (suggestionRow) window.Lens.core.DOM.show(suggestionRow);
        },

        _handleAltProxySave: function (e, btn) {
            if (btn.disabled) return;

            var proxy = btn.closest('[data-lens-target="alt-proxy-field"]');
            if (!proxy) return;

            var assetId = proxy.dataset.lensAssetId;
            var input = proxy.querySelector('[data-lens-target="alt-proxy-input"]');
            if (!input) return;

            var value = input.value;

            window.Lens.core.ButtonState.withLoading(
                btn,
                Craft.t('lens', 'Saving'),
                () => {
                    return window.Lens.core.API.updateAssetAlt(
                        assetId,
                        value,
                    ).then((response) => {
                        if (response.data.success) {
                            Craft.cp.displayNotice(
                                Craft.t('lens', 'Alt text updated.'),
                            );

                            // Hide edit area
                            var editArea = proxy.querySelector('[data-lens-target="alt-proxy-edit-area"]');
                            if (editArea) window.Lens.core.DOM.hide(editArea);

                            // Update current value display
                            var currentDisplay = proxy.querySelector('[data-lens-target="alt-proxy-current"]');
                            var suggestionRow = proxy.querySelector('[data-lens-target="alt-proxy-suggestion-row"]');
                            var suggestionText = suggestionRow ? suggestionRow.querySelector('[data-lens-target="alt-proxy-suggestion-text"]') : null;
                            var suggestionValue = suggestionText ? suggestionText.textContent.trim() : '';

                            // Find the trailing action element (Applied indicator or Apply link)
                            var appliedIndicator = suggestionRow ? suggestionRow.querySelector('.lens-applied-indicator') : null;
                            var applyLink = proxy.querySelector('[data-lens-target="alt-proxy-apply-link"]');

                            if (value === suggestionValue) {
                                // Values now match — hide current display, show Applied
                                if (currentDisplay) window.Lens.core.DOM.hide(currentDisplay);
                                if (applyLink) {
                                    var applied = this._cloneAppliedIndicator();
                                    if (applied) applyLink.replaceWith(applied);
                                }
                            } else {
                                // Values differ — show/update current display
                                if (!currentDisplay) {
                                    currentDisplay = document.createElement('div');
                                    currentDisplay.className = 'lens-field-display';
                                    currentDisplay.dataset.lensTarget = 'alt-proxy-current';
                                    if (suggestionRow) {
                                        suggestionRow.parentNode.insertBefore(currentDisplay, suggestionRow);
                                    }
                                }
                                if (value) {
                                    currentDisplay.innerHTML = '<p>' + window.Lens.utils.escapeHtml(value) + '</p>';
                                } else {
                                    currentDisplay.innerHTML = '<p class="light">' + Craft.t('lens', 'Empty') + '</p>';
                                }
                                window.Lens.core.DOM.show(currentDisplay);

                                // Swap Applied indicator back to Apply to Asset button
                                if (appliedIndicator && suggestionRow) {
                                    var applyBtn = document.createElement('button');
                                    applyBtn.type = 'button';
                                    applyBtn.className = 'lens-revert-trigger';
                                    applyBtn.dataset.lensTarget = 'alt-proxy-apply-link';
                                    applyBtn.dataset.lensAction = 'apply-alt';
                                    applyBtn.dataset.lensAssetId = proxy.dataset.lensAssetId;
                                    applyBtn.dataset.lensAnalysisId = proxy.dataset.lensAnalysisId;
                                    if (proxy.dataset.lensSiteId) {
                                        applyBtn.dataset.lensSiteId = proxy.dataset.lensSiteId;
                                    }
                                    applyBtn.textContent = Craft.t('lens', 'Apply to Asset');
                                    appliedIndicator.replaceWith(applyBtn);
                                }
                            }

                            if (suggestionRow) window.Lens.core.DOM.show(suggestionRow);

                            // Sync Craft's native alt input (if AltField happens to be in layout elsewhere)
                            this._syncNativeField('alt', value);
                        }
                    });
                },
            );
        },

        /**
         * Sync a Craft CMS native field input after we've changed the asset value via API.
         * Prevents the stale input value from overwriting our change on next save.
         *
         * @param {string} fieldName - 'title' or 'alt'
         * @param {string} value - The new value
         */
        _syncNativeField: function (fieldName, value) {
            var input = document.getElementById(fieldName) ||
                        document.querySelector(
                            'input[name="' + fieldName + '"], textarea[name="' + fieldName + '"]'
                        );

            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                window.Lens.core.DOM.resetFormBaseline(input);
            }

            if (fieldName === 'title') {
                var heading = document.querySelector('#header h1, .so-header h1');
                if (heading) {
                    heading.textContent = value;
                }
            }
        },

        _handleApplyFocalPoint: function (e, btn) {
            if (btn.disabled) return;

            const assetId = btn.dataset.lensAssetId;
            const focalX = parseFloat(btn.dataset.lensFocalX);
            const focalY = parseFloat(btn.dataset.lensFocalY);

            if (isNaN(focalX) || isNaN(focalY)) {
                Craft.cp.displayError(
                    Craft.t('lens', 'Invalid focal point coordinates.'),
                );
                return;
            }

            window.Lens.core.ButtonState.withLoading(
                btn,
                Craft.t('lens', 'Applying…'),
                () => {
                    return window.Lens.core.API.applyFocalPoint(assetId, {
                        x: focalX,
                        y: focalY,
                    }).then((response) => {
                        if (response.data.success) {
                            // Swap link for Applied indicator
                            var applied = this._cloneAppliedIndicator();
                            if (applied) btn.replaceWith(applied);

                            Craft.cp.displayNotice(
                                Craft.t('lens', 'Focal point applied to asset.'),
                            );
                        }
                    });
                },
            );
        },

        // ================================================================
        // Translations (collapse/expand, summary expand)
        // ================================================================

        initTranslations: function () {
            var DOM = window.Lens.core.DOM;

            // Toggle collapse/expand
            DOM.delegate(
                '[data-lens-action="toggle-translations"]',
                'click',
                this._handleTranslationToggle.bind(this),
            );

            // Summary row expand (review page)
            DOM.delegate(
                '[data-lens-action="translation-expand"]',
                'click',
                this._handleTranslationExpand.bind(this),
            );
        },

        _handleTranslationToggle: function (e, header) {
            var section = header.closest('[data-lens-target="translation-summary"]');
            if (!section) return;

            var body = section.querySelector('[data-lens-target="translations-body"]');
            if (!body) return;

            var isExpanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
            window.Lens.core.DOM.toggleClass(body, !isExpanded);
        },

        _handleTranslationExpand: function (e, row) {
            var summary = row.closest('[data-lens-target="translation-summary"]');
            if (!summary) return;

            var siteId = row.dataset.lensExpandSite;
            if (!siteId) return;

            // Accordion: collapse any currently expanded detail
            var allDetails = summary.querySelectorAll('[data-lens-target="translation-detail"]');
            for (var i = 0; i < allDetails.length; i++) {
                var detail = allDetails[i];
                if (detail.dataset.lensSiteId === siteId) {
                    // Toggle this one
                    detail.classList.toggle('hidden');
                } else {
                    detail.classList.add('hidden');
                }
            }
        },

        // ================================================================
        // Auto-Polling
        // ================================================================

        initAutoPolling: function () {
            const panel = document.querySelector('[data-lens-target="analysis-panel"]');
            if (!panel) return;

            const status = panel.dataset.lensAnalysisStatus;
            const assetId = panel.dataset.lensAssetId;

            // If analysis is pending or processing, start polling using service
            var S = window.Lens.config.STATUS;

            if (status === S.PENDING || status === S.PROCESSING) {
                window.Lens.services.AssetProcessing.poll(
                    parseInt(assetId, 10),
                );
            }
        },
    };

    window.Lens.pages.AnalysisPanel = LensAnalysisPanel;

    // Auto-initialize
    Lens.utils.onReady(function() {
        // Translation delegates use event delegation and work on both
        // analysis panel and review page — register unconditionally
        LensAnalysisPanel.initTranslations();
        LensAnalysisPanel.init();
    });
})();
