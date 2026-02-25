/**
 * Lens Plugin - Configuration
 * Centralized constants, thresholds, and configuration values
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};

    /**
     * Configuration object containing all constants and thresholds
     */
    window.Lens.config = {
        /**
         * Polling configuration for asset analysis status checking
         */
        POLLING: {
            /**
             * Exponential backoff intervals in milliseconds
             * Starts at 3s, increases to 60s max
             */
            INTERVALS: [3000, 5000, 8000, 10000, 15000, 20000, 30000, 60000],

            /**
             * Maximum number of polling attempts before giving up
             */
            MAX_ATTEMPTS: 40,

            /**
             * Debounce delay for tag autocomplete (milliseconds)
             */
            TAG_AUTOCOMPLETE_DEBOUNCE_MS: 250
        },

        /**
         * Confidence and quality thresholds
         */
        THRESHOLDS: {
            /**
             * Threshold for high confidence badge (green)
             */
            HIGH_CONFIDENCE: 0.8,

            /**
             * Threshold for medium confidence badge (yellow)
             */
            MEDIUM_CONFIDENCE: 0.5,

            /**
             * Minimum characters before showing tag autocomplete
             */
            TAG_AUTOCOMPLETE_MIN_CHARS: 2
        },

        /**
         * Animation timing configuration
         */
        ANIMATION: {
            /**
             * Fade out duration in milliseconds
             */
            FADE_MS: 300,

            /**
             * Delay before showing AI suggestion in milliseconds
             */
            SUGGESTION_DELAY_MS: 200,

            /**
             * Delay before reloading panel after flag dismissal
             */
            RELOAD_DELAY_MS: 1000,

            /**
             * Debounce delay for form field re-enabling
             */
            FORM_FIELD_DEBOUNCE_MS: 100
        },

        /**
         * People detection configuration
         */
        PEOPLE_DETECTION: {
            /**
             * Mode constants for people detection radio values
             */
            MODES: {
                NONE: 'none',
                NO_FACES: 'no-faces',
                ONE: '1',
                TWO: '2',
                SMALL_GROUP: '3-5',
                LARGE_GROUP: '6+'
            },

            /**
             * Midpoint values for tier ranges (used in reverse mapping)
             * When converting faceCount to tier, use these representative values
             */
            TIER_MIDPOINTS: {
                '3-5': 4,
                '6+': 6
            }
        },

        /**
         * Storage keys for localStorage
         */
        STORAGE: {
            DISMISSED_NOTICES: 'lens_dismissed_notices'
        }
    };
})();
