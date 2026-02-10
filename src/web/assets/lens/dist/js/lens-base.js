/**
 * Lens Plugin - Base Module
 * Namespace initialization and coordination
 *
 * Note: This file has been refactored as part of the architectural improvements.
 * - Utilities moved to: core/lens-utils.js
 * - Dismissible notices moved to: components/lens-dismissible-notices.js
 * - Services in: services/
 * - Components in: components/
 * - Page controllers in: pages/
 */
(function() {
    'use strict';

    /**
     * Initialize Lens namespace
     * Submodules will attach to this namespace:
     * - window.Lens.config (lens-config.js)
     * - window.Lens.utils (core/lens-utils.js)
     * - window.Lens.core.DOM, ButtonState, API (core/)
     * - window.Lens.services.* (services/)
     * - window.Lens.components.* (components/)
     * - window.Lens.pages.* (pages/)
     */
    window.Lens = window.Lens || {};
    window.Lens.core = window.Lens.core || {};
    window.Lens.utils = window.Lens.utils || {};
    window.Lens.services = window.Lens.services || {};
    window.Lens.components = window.Lens.components || {};
    window.Lens.pages = window.Lens.pages || {};
})();
