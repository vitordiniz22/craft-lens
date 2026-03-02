/**
 * Lens Plugin - Button State Manager
 * Centralized button loading state management
 * Eliminates 13+ instances of manual button state manipulation
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.core = window.Lens.core || {};

    /**
     * Button state management utilities
     */
    window.Lens.core.ButtonState = {
        /**
         * Set button to loading state
         * @param {HTMLElement} button - Button element
         * @param {string} [loadingText] - Optional loading text (defaults to "Loading...")
         * @param {Object} [options]
         * @param {string} [options.labelSelector] - Child selector for text (e.g. '.label'). When set, updates child text instead of button.textContent
         * @param {string} [options.loadingClass] - CSS class to add during loading (e.g. 'loading')
         * @returns {Function} Restore function to revert button to original state
         */
        setLoading: function(button, loadingText, options) {
            if (!button) return function() {};

            options = options || {};
            var labelEl = options.labelSelector ? button.querySelector(options.labelSelector) : null;
            var textTarget = labelEl || button;
            var originalText = textTarget.textContent;
            var originalDisabled = button.disabled;

            // Set loading state
            button.disabled = true;
            if (loadingText) textTarget.textContent = loadingText;
            if (options.loadingClass) button.classList.add(options.loadingClass);

            // Return restore function
            return function() {
                button.disabled = originalDisabled;
                textTarget.textContent = originalText;
                if (options.loadingClass) button.classList.remove(options.loadingClass);
            };
        },

        /**
         * Disable a button
         * @param {HTMLElement} button - Button element
         */
        disable: function(button) {
            if (!button) return;
            button.disabled = true;
        },

        /**
         * Enable a button
         * @param {HTMLElement} button - Button element
         */
        enable: function(button) {
            if (!button) return;
            button.disabled = false;
        },

        /**
         * Wrap an operation with loading state
         * Automatically handles loading state, errors, and restoration
         * @param {HTMLElement} button - Button element
         * @param {string} loadingText - Loading text to display
         * @param {Function} operation - Operation to execute (can return Promise)
         * @param {Object} [options] - Same options as setLoading (labelSelector, loadingClass)
         * @returns {Promise} Promise that resolves when operation completes
         */
        withLoading: function(button, loadingText, operation, options) {
            if (!button) {
                return Promise.reject(new Error('Button element is required'));
            }

            const restore = this.setLoading(button, loadingText, options);

            // Execute operation
            try {
                const result = operation();

                // Handle promise-based operations
                if (result && typeof result.then === 'function') {
                    return result.finally(function() {
                        restore();
                    });
                }

                // Handle synchronous operations
                restore();
                return Promise.resolve(result);
            } catch (error) {
                restore();
                return Promise.reject(error);
            }
        }
    };
})();
