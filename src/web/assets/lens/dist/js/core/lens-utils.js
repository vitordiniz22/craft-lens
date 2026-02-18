/**
 * Lens Plugin - Core Utilities
 * Shared formatting, escaping, and display utilities
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    /**
     * Core utilities for formatting and escaping
     */
    window.Lens.utils = {
        /**
         * Escape HTML special characters to prevent XSS
         * @param {string} text - Text to escape
         * @returns {string} HTML-safe text
         */
        escapeHtml: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        },

        /**
         * Format people detection text for display
         * Centralized to keep Twig and JS in sync
         * @param {boolean} containsPeople - Whether people are detected
         * @param {number} faceCount - Number of visible faces
         * @returns {string} Formatted text (e.g., "People present, no visible faces")
         */
        formatPeopleDetectionText: function(containsPeople, faceCount) {
            if (!containsPeople) {
                return Craft.t('lens', 'No people present');
            }

            if (faceCount === 0) {
                return Craft.t('lens', 'People present, no visible faces');
            } else if (faceCount === 1) {
                return Craft.t('lens', 'Individual (1 person)');
            } else if (faceCount === 2) {
                return Craft.t('lens', 'Duo (2 people)');
            } else if (faceCount >= 3 && faceCount <= 5) {
                return Craft.t('lens', 'Small group (3-5 people)');
            } else if (faceCount >= 6) {
                return Craft.t('lens', 'Large group (6+ people)');
            }

            return '';
        }
    };
})();
