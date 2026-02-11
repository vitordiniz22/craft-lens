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
     * Coordinates InlineEditor, TagEditor, ColorEditor, and SafetyFlags components
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
            this.initTaxonomySave();
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
            return document.querySelector('.lens-analysis-panel') !== null;
        },

        // ================================================================
        // Taxonomy Save (Tags + Colors Batch)
        // ================================================================

        initTaxonomySave: function () {
            window.Lens.core.DOM.delegate(
                '[data-lens-action="taxonomy-save"]',
                'click',
                this._handleTaxonomySave.bind(this),
            );
        },

        _handleTaxonomySave: function (e, saveBtn) {
            if (saveBtn.disabled) return;

            const analysisId = saveBtn.dataset.lensAnalysisId;
            const section = saveBtn.closest('.lens-section');
            if (!section) return;

            window.Lens.core.ButtonState.withLoading(
                saveBtn,
                Craft.t('lens', 'Saving'),
                () => {
                    // Collect tags using service
                    const tagEditor = section.querySelector(
                        '[data-lens-target="tag-editor"]',
                    );
                    const tags =
                        window.Lens.services.Taxonomy.collectTags(tagEditor);

                    // Collect colors using service
                    const colorEditor = section.querySelector(
                        '[data-lens-target="color-editor"]',
                    );
                    const colors =
                        window.Lens.services.Taxonomy.collectColors(
                            colorEditor,
                        );

                    // Save both via parallel API calls
                    const tagPromise = window.Lens.core.API.post(
                        'lens/analysis/update-tags',
                        {
                            analysisId: analysisId,
                            tags: JSON.stringify(tags),
                        },
                    );

                    const colorPromise = window.Lens.core.API.post(
                        'lens/analysis/update-colors',
                        {
                            analysisId: analysisId,
                            colors: JSON.stringify(colors),
                        },
                    );

                    return Promise.all([tagPromise, colorPromise]).then(() => {
                        Craft.cp.displayNotice(
                            Craft.t('lens', 'Taxonomy saved.'),
                        );
                        const status = section.querySelector(
                            '[data-lens-target="taxonomy-status"]',
                        );
                        if (status) status.textContent = '';
                        saveBtn.textContent = Craft.t('lens', 'Save Changes');
                    });
                },
            );
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
                '[data-lens-action="apply-focal-point"]',
                'click',
                this._handleApplyFocalPoint.bind(this),
            );
            window.Lens.core.DOM.delegate(
                '[data-lens-action="find-similar"]',
                'click',
                this._handleFindSimilar.bind(this),
            );
        },

        _handleAnalyze: function (e, btn) {
            if (btn.disabled) return;

            const assetId = btn.dataset.lensAssetId;
            const label = btn.querySelector('.label');
            const loadingText = btn.dataset.lensLoadingText;
            const originalText = label ? label.textContent : '';

            btn.disabled = true;
            btn.classList.add('loading');

            if (label && loadingText) label.textContent = loadingText;

            var restoreBtn = function () {
                btn.disabled = false;
                btn.classList.remove('loading');
                if (label) label.textContent = originalText;
            };

            window.Lens.core.API.post('lens/analysis/reprocess', {
                assetId: assetId,
            })
                .then(function (response) {
                    if (response.data.success) {
                        Craft.cp.displayNotice(
                            Craft.t('lens', 'Asset queued for analysis.'),
                        );

                        if (label)
                            label.textContent = Craft.t('lens', 'Analyzing');

                        // Keep loading state throughout polling — only release on failure
                        window.Lens.services.AssetProcessing.poll(assetId, {
                            onError: restoreBtn,
                            onMaxAttempts: restoreBtn,
                        });
                    }
                })
                .catch(restoreBtn);
        },

        _handleApplyTitle: function (e, btn) {
            if (btn.disabled) return;

            const assetId = btn.dataset.lensAssetId;
            const analysisId = btn.dataset.lensAnalysisId;

            window.Lens.core.ButtonState.withLoading(
                btn,
                Craft.t('lens', 'Applying'),
                () => {
                    return window.Lens.core.API.applyTitle(
                        assetId,
                        analysisId,
                    ).then((response) => {
                        if (response.data.success) {
                            Craft.cp.displayNotice(
                                Craft.t('lens', 'Title applied to asset.'),
                            );
                        }
                    });
                },
            );
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
                Craft.t('lens', 'Applying'),
                () => {
                    return window.Lens.core.API.applyFocalPoint(assetId, {
                        x: focalX,
                        y: focalY,
                    }).then((response) => {
                        if (response.data.success) {
                            Craft.cp.displayNotice(
                                Craft.t(
                                    'lens',
                                    'Focal point applied to asset.',
                                ),
                            );
                        }
                    });
                },
            );
        },

        _handleFindSimilar: function (e, btn) {
            if (btn.disabled) return;

            const assetId = btn.dataset.lensAssetId;
            const url = Craft.getCpUrl('lens/search', { assetId: assetId });

            window.location.href = url;
        },

        // ================================================================
        // Auto-Polling
        // ================================================================

        initAutoPolling: function () {
            const panel = document.querySelector('.lens-analysis-panel');
            if (!panel) return;

            const status = panel.dataset.lensAnalysisStatus;
            const assetId = panel.dataset.lensAssetId;

            // If analysis is pending or processing, start polling using service
            if (status === 'pending' || status === 'processing') {
                window.Lens.services.AssetProcessing.poll(
                    parseInt(assetId, 10),
                );
            }
        },
    };

    window.Lens.pages.AnalysisPanel = LensAnalysisPanel;

    // Auto-initialize
    function init() {
        LensAnalysisPanel.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
