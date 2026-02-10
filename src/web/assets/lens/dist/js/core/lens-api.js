/**
 * Lens Plugin - API Wrapper
 * Centralized Craft CMS API communication with error handling
 * Eliminates 21+ instances of manual API calls with repeated error patterns
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.core = window.Lens.core || {};

    /**
     * API communication utilities
     */
    window.Lens.core.API = {
        /**
         * Send a request to Craft CMS action
         * @param {string} method - HTTP method ('GET' or 'POST')
         * @param {string} action - Craft action path (e.g., 'lens/analysis/update-field')
         * @param {Object} [data={}] - Request data
         * @param {Object} [options={}] - Additional options
         * @param {boolean} [options.showSuccessNotice=false] - Show success notification
         * @param {string} [options.successMessage] - Custom success message
         * @param {boolean} [options.showErrorNotice=true] - Show error notification
         * @param {string} [options.errorMessage] - Custom error message
         * @param {boolean} [options.logErrors=true] - Log errors to console
         * @returns {Promise} Promise resolving with response data
         */
        request: function(method, action, data, options) {
            data = data || {};
            options = options || {};

            return Craft.sendActionRequest(method, action, { data: data })
                .then(function(response) {
                    // Show success notice if requested
                    if (options.showSuccessNotice) {
                        const message = options.successMessage || Craft.t('lens', 'Request completed successfully');
                        Craft.cp.displayNotice(message);
                    }

                    return response;
                })
                .catch(function(error) {
                    // Log error if requested
                    if (options.logErrors !== false) {
                        console.error('[Lens API] ' + action + ' failed:', error);
                    }

                    // Show error notice if requested
                    if (options.showErrorNotice !== false) {
                        const message = options.errorMessage ||
                                      (error.response && error.response.data && error.response.data.error) ||
                                      Craft.t('lens', 'Request failed');
                        Craft.cp.displayError(message);
                    }

                    // Re-throw for caller to handle
                    throw error;
                });
        },

        /**
         * Send GET request
         * @param {string} action - Craft action path
         * @param {Object} [params={}] - Query parameters
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        get: function(action, params, options) {
            return this.request('GET', action, params, options);
        },

        /**
         * Send POST request
         * @param {string} action - Craft action path
         * @param {Object} [data={}] - Request body data
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        post: function(action, data, options) {
            return this.request('POST', action, data, options);
        },

        /**
         * Update an analysis field
         * @param {string|number} analysisId - Analysis ID
         * @param {string} field - Field name
         * @param {*} value - Field value
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        updateField: function(analysisId, field, value, options) {
            return this.post('lens/analysis/update-field', {
                analysisId: analysisId,
                field: field,
                value: value
            }, options);
        },

        /**
         * Revert an analysis field to AI value
         * @param {string|number} analysisId - Analysis ID
         * @param {string} field - Field name
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        revertField: function(analysisId, field, options) {
            return this.post('lens/analysis/revert-field', {
                analysisId: analysisId,
                field: field
            }, options);
        },

        /**
         * Trigger asset analysis
         * @param {string|number} assetId - Asset ID
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        analyzeAsset: function(assetId, options) {
            return this.post('lens/analysis/analyze', {
                assetId: assetId
            }, options);
        },

        /**
         * Check analysis status
         * @param {string|number} assetId - Asset ID
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with status data
         */
        checkStatus: function(assetId, options) {
            // Disable error notices for status checks (expected to fail sometimes)
            const statusOptions = Object.assign({}, options, {
                showErrorNotice: false,
                logErrors: false
            });

            return this.get('lens/analysis/status', { assetId: assetId }, statusOptions);
        },

        /**
         * Dismiss a safety flag
         * @param {string|number} analysisId - Analysis ID
         * @param {string} flagType - Flag type to dismiss
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        dismissFlag: function(analysisId, flagType, options) {
            return this.post('lens/analysis/dismiss-flag', {
                analysisId: analysisId,
                flag: flagType
            }, options);
        },

        /**
         * Save taxonomy data (tags and colors)
         * @param {string|number} analysisId - Analysis ID
         * @param {Object} taxonomyData - Taxonomy data {tags: [], colors: []}
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        saveTaxonomy: function(analysisId, taxonomyData, options) {
            return this.post('lens/analysis/save-taxonomy', {
                analysisId: analysisId,
                tags: taxonomyData.tags || [],
                colors: taxonomyData.colors || []
            }, options);
        },

        /**
         * Fetch tag suggestions
         * @param {string} query - Search query
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with tag suggestions
         */
        fetchTagSuggestions: function(query, options) {
            const suggestOptions = Object.assign({}, options, {
                showErrorNotice: false,
                logErrors: false
            });

            return this.get('lens/analysis/tag-suggestions', { q: query }, suggestOptions);
        },

        /**
         * Apply title to asset
         * @param {string|number} assetId - Asset ID
         * @param {string|number} analysisId - Analysis ID
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        applyTitle: function(assetId, analysisId, options) {
            return this.post('lens/analysis/apply-title', {
                assetId: assetId,
                analysisId: analysisId
            }, options);
        },

        /**
         * Apply focal point to asset
         * @param {string|number} assetId - Asset ID
         * @param {Object} focalPoint - Focal point {x, y} (0-1 normalized)
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        applyFocalPoint: function(assetId, focalPoint, options) {
            return this.post('lens/analysis/apply-focal-point', {
                assetId: assetId,
                focalPoint: focalPoint
            }, options);
        }
    };
})();
