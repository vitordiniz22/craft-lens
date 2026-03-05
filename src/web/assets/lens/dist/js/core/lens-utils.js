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
         * Run a function when the DOM is ready
         * @param {Function} fn - Function to run
         */
        onReady: function(fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        },

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
        },

        /**
         * Build edit meta HTML with linked user name
         * @param {Object} data - API response data
         * @param {string} data.editedBy - User display name
         * @param {string|null} data.editedByUrl - User CP edit URL
         * @param {string} data.editedAtFormatted - Locale-formatted date string
         * @returns {string} HTML string for innerHTML
         */
        formatEditMeta: function(data) {
            if (!data || !data.editedBy) return '';

            var escapedName = Lens.utils.escapeHtml(data.editedBy);
            var userHtml;

            if (data.editedByUrl) {
                userHtml = '<a href="' + Lens.utils.escapeHtml(data.editedByUrl) + '">' + escapedName + '</a>';
            } else {
                userHtml = escapedName;
            }

            return Craft.t('lens', 'Edited by {user} on {date}', {
                user: userHtml,
                date: Lens.utils.escapeHtml(data.editedAtFormatted || data.editedAt || '')
            });
        },

        /**
         * Safely reload the page, preventing multiple simultaneous reloads.
         * @param {number} [delay=0] - Optional delay in ms before reload
         */
        safeReload: function(delay) {
            if (window._lensReloading) return;
            window._lensReloading = true;

            if (delay) {
                setTimeout(function() { window.location.reload(); }, delay);
            } else {
                window.location.reload();
            }
        }
    };
})();
