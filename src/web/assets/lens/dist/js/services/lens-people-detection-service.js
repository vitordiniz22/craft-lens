/**
 * Lens Plugin - People Detection Service
 * Centralized people detection mode mapping and formatting
 * Eliminates duplicate switch statements in lens-asset-actions.js and lens-review.js
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.services = window.Lens.services || {};

    /**
     * People Detection Service
     * Single source of truth for people detection mode conversions
     */
    window.Lens.services.PeopleDetection = {
        /**
         * Get mode constants from config
         */
        get MODES() {
            return window.Lens.config.PEOPLE_DETECTION.MODES;
        },

        /**
         * Convert radio mode to field values
         * @param {string} mode - Radio value ('none', 'no-faces', '1', '2', '3-5', '6+')
         * @returns {{containsPeople: boolean, faceCount: number}|null} Field values or null if invalid
         */
        modeToFields: function(mode) {
            if (!mode) return null;

            switch (mode) {
                case this.MODES.NONE:
                    return { containsPeople: false, faceCount: 0 };

                case this.MODES.NO_FACES:
                    return { containsPeople: true, faceCount: 0 };

                case this.MODES.ONE:
                    return { containsPeople: true, faceCount: 1 };

                case this.MODES.TWO:
                    return { containsPeople: true, faceCount: 2 };

                case this.MODES.SMALL_GROUP:
                    return { containsPeople: true, faceCount: 4 };

                case this.MODES.LARGE_GROUP:
                    return { containsPeople: true, faceCount: 6 };

                default:
                    return null;
            }
        },

        /**
         * Convert field values to radio mode
         * Reverse mapping for displaying current selection
         * @param {boolean} containsPeople - Whether people are detected
         * @param {number} faceCount - Number of visible faces
         * @returns {string} Radio mode value
         */
        fieldsToMode: function(containsPeople, faceCount) {
            if (!containsPeople) {
                return this.MODES.NONE;
            }

            if (faceCount === 0) {
                return this.MODES.NO_FACES;
            } else if (faceCount === 1) {
                return this.MODES.ONE;
            } else if (faceCount === 2) {
                return this.MODES.TWO;
            } else if (faceCount >= 3 && faceCount <= 5) {
                return this.MODES.SMALL_GROUP;
            } else if (faceCount >= 6) {
                return this.MODES.LARGE_GROUP;
            }

            return this.MODES.NONE;
        },

        /**
         * Get display text for people detection
         * Delegates to utils for consistency with Twig templates
         * @param {boolean} containsPeople - Whether people are detected
         * @param {number} faceCount - Number of visible faces
         * @returns {string} Formatted display text
         */
        formatText: function(containsPeople, faceCount) {
            return window.Lens.utils.formatPeopleDetectionText(containsPeople, faceCount);
        }
    };
})();
