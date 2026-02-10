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
         * @returns {Function} Restore function to revert button to original state
         */
        setLoading: function(button, loadingText) {
            if (!button) return function() {};

            // Store original state
            const originalText = button.textContent;
            const originalDisabled = button.disabled;

            // Set loading state
            button.disabled = true;
            button.textContent = loadingText || Craft.t('lens', 'Loading...');

            // Return restore function
            return function() {
                button.disabled = originalDisabled;
                button.textContent = originalText;
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
         * @returns {Promise} Promise that resolves when operation completes
         */
        withLoading: function(button, loadingText, operation) {
            if (!button) {
                return Promise.reject(new Error('Button element is required'));
            }

            const restore = this.setLoading(button, loadingText);

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
        },

        /**
         * Set multiple buttons to same state
         * @param {HTMLElement[]} buttons - Array of button elements
         * @param {string} loadingText - Loading text to display
         * @returns {Function} Restore function for all buttons
         */
        setMultipleLoading: function(buttons, loadingText) {
            if (!buttons || !buttons.length) return function() {};

            const restoreFunctions = buttons.map(function(button) {
                return this.setLoading(button, loadingText);
            }, this);

            // Return combined restore function
            return function() {
                restoreFunctions.forEach(function(restore) {
                    restore();
                });
            };
        },

        /**
         * Temporarily disable a button for specified duration
         * Useful for preventing double-clicks
         * @param {HTMLElement} button - Button element
         * @param {number} [duration=1000] - Duration in milliseconds
         */
        temporarilyDisable: function(button, duration) {
            if (!button) return;

            const originalDisabled = button.disabled;
            button.disabled = true;

            setTimeout(function() {
                button.disabled = originalDisabled;
            }, duration || 1000);
        }
    };
})();
