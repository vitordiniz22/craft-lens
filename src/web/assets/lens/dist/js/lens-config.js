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
            TAG_AUTOCOMPLETE_DEBOUNCE_MS: 250,

            /**
             * Debounce delay for semantic search (milliseconds)
             */
            SEMANTIC_SEARCH_DEBOUNCE_MS: 350
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
            TAG_AUTOCOMPLETE_MIN_CHARS: 2,

            /**
             * Maximum tag character length (matches DB column)
             */
            TAG_MAX_LENGTH: 255,

            /**
             * Show character counter above this input length
             */
            TAG_LENGTH_WARNING_THRESHOLD: 200,

            /**
             * Tag input near-limit visual threshold
             */
            TAG_LENGTH_NEAR_LIMIT: 240,

            /**
             * Bridge review AI text truncation length
             */
            AI_PREVIEW_LENGTH: 60,

            /**
             * Inline editor AI suggestion truncation length
             */
            AI_SUGGESTION_PREVIEW_LENGTH: 100
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
            FORM_FIELD_DEBOUNCE_MS: 100,

            /**
             * Duration of duplicate chip flash animation
             */
            CHIP_FLASH_MS: 800,

            /**
             * Duration of new chip entrance animation
             */
            CHIP_APPEAR_MS: 200,

            /**
             * Focal point hint auto-dismiss delay
             */
            HINT_AUTO_DISMISS_MS: 4000,

            /**
             * Focal point hint fade transition
             */
            HINT_FADE_MS: 300,

            /**
             * Bulk processing progress poll interval
             */
            BULK_PROGRESS_POLL_MS: 5000
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
         * Analysis status values (mirrors AnalysisStatus PHP enum)
         */
        STATUS: {
            PENDING: 'pending',
            PROCESSING: 'processing',
            COMPLETED: 'completed',
            FAILED: 'failed',
            PENDING_REVIEW: 'pending_review',
            APPROVED: 'approved',
            REJECTED: 'rejected'
        },

        /**
         * Search configuration
         */
        SEARCH: {
            /**
             * Maximum results for semantic search modal
             */
            MODAL_LIMIT: 50,

            /**
             * Minimum query length before executing search
             */
            MIN_QUERY_LENGTH: 2
        },

        /**
         * Storage keys for localStorage
         */
        STORAGE: {
            DISMISSED_NOTICES: 'lens_dismissed_notices'
        }
    };
})();
