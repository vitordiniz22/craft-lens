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
         * @returns {Promise} Promise resolving with response data
         */
        request: function(method, action, data, options) {
            data = data || {};
            options = options || {};

            var requestConfig = method === 'GET' ? { params: data } : { data: data };
            return Craft.sendActionRequest(method, action, requestConfig)
                .then(function(response) {
                    // Show success notice if requested
                    if (options.showSuccessNotice) {
                        const message = options.successMessage || Craft.t('lens', 'Request completed successfully');
                        Craft.cp.displayNotice(message);
                    }

                    return response;
                })
                .catch(function(error) {
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
            var data = {
                analysisId: analysisId,
                field: field,
                value: value
            };

            if (options && options.siteId) {
                data.siteId = options.siteId;
            }

            return this.post('lens/analysis/update-field', data, options);
        },

        /**
         * Revert an analysis field to AI value
         * @param {string|number} analysisId - Analysis ID
         * @param {string} field - Field name
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        revertField: function(analysisId, field, options) {
            var data = {
                analysisId: analysisId,
                field: field
            };

            if (options && options.siteId) {
                data.siteId = options.siteId;
            }

            return this.post('lens/analysis/revert-field', data, options);
        },

        /**
         * Fetch tag suggestions
         * @param {string} query - Search query
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with tag suggestions
         */
        fetchTagSuggestions: function(query, options) {
            const suggestOptions = Object.assign({}, options, {
                showErrorNotice: false
            });

            return this.get('lens/analysis/tag-suggestions', { query: query }, suggestOptions);
        },

        /**
         * Apply title to asset
         * @param {string|number} assetId - Asset ID
         * @param {string|number} analysisId - Analysis ID
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        applyTitle: function(assetId, analysisId, options) {
            var data = {
                assetId: assetId,
                analysisId: analysisId
            };

            if (options && options.siteId) {
                data.siteId = options.siteId;
            }

            return this.post('lens/analysis/apply-title', data, options);
        },

        /**
         * Apply alt text suggestion to asset
         * @param {string|number} assetId - Asset ID
         * @param {string|number} analysisId - Analysis ID
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        applyAlt: function(assetId, analysisId, options) {
            var data = {
                assetId: assetId,
                analysisId: analysisId
            };

            if (options && options.siteId) {
                data.siteId = options.siteId;
            }

            return this.post('lens/analysis/apply-alt', data, options);
        },

        /**
         * Update asset alt text directly (proxy field)
         * @param {string|number} assetId - Asset ID
         * @param {string} value - New alt text value
         * @param {Object} [options={}] - Additional options
         * @returns {Promise} Promise resolving with response data
         */
        updateAssetAlt: function(assetId, value, options) {
            return this.post('lens/analysis/update-asset-alt', {
                assetId: assetId,
                value: value
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
