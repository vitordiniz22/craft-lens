/**
 * Lens Plugin - Expander Component
 * Generic collapsible group. Any button with [data-lens-action="toggle-expander"]
 * inside a [data-lens-target="expander"] wrapper toggles the sibling
 * [data-lens-target="expander-content"] panel. Pairs with components/expander.css.
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.components = window.Lens.components || {};

    var LensExpander = {
        _initialized: false,

        init: function() {
            if (this._initialized) return;
            this._bindEvents();
            this._initialized = true;
        },

        _bindEvents: function() {
            Lens.core.DOM.delegate('[data-lens-action="toggle-expander"]', 'click', function(e, btn) {
                var wrapper = btn.closest('[data-lens-target="expander"]');
                if (!wrapper) return;
                var content = wrapper.querySelector('[data-lens-target="expander-content"]');
                if (!content) return;

                var isExpanded = !content.hidden;
                Lens.core.DOM.toggle(content, !isExpanded);
                wrapper.classList.toggle('lens-expander--expanded', !isExpanded);
                btn.setAttribute('aria-expanded', String(!isExpanded));
            });
        },
    };

    window.Lens.components.Expander = LensExpander;

    Lens.utils.onReady(function() {
        LensExpander.init();
    });
})();
