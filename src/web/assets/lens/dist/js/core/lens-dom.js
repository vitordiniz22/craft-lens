/**
 * Lens Plugin - DOM Utilities
 * DOM manipulation helpers and event delegation
 */
(function() {
    'use strict';

    window.Lens = window.Lens || {};
    window.Lens.core = window.Lens.core || {};

    /**
     * DOM utility functions
     */
    window.Lens.core.DOM = {
        /**
         * Show an element
         * @param {HTMLElement} element - Element to show
         */
        show: function(element) {
            if (!element) return;
            element.style.display = '';
            element.hidden = false;
        },

        /**
         * Hide an element
         * @param {HTMLElement} element - Element to hide
         */
        hide: function(element) {
            if (!element) return;
            element.style.display = 'none';
            element.hidden = true;
        },

        /**
         * Toggle element visibility using show/hide
         * @param {HTMLElement} element - Element to toggle
         * @param {boolean} visible - Show when true, hide when false
         */
        toggle: function(element, visible) {
            if (!element) return;
            if (visible) {
                this.show(element);
            } else {
                this.hide(element);
            }
        },

        /**
         * Toggle visibility using 'hidden' class (additive, doesn't override display)
         * @param {HTMLElement} element - Element to toggle
         * @param {boolean} [visible] - Optional explicit visibility state
         */
        toggleClass: function(element, visible) {
            if (!element) return;

            if (visible === undefined) {
                element.classList.toggle('hidden');
            } else {
                if (visible) {
                    element.classList.remove('hidden');
                } else {
                    element.classList.add('hidden');
                }
            }
        },

        /**
         * Event delegation helper - attach event to document and filter by selector
         * Eliminates 20+ instances of manual event delegation boilerplate
         * @param {string} selector - Selector to match (e.g., '[data-lens-action="edit"]')
         * @param {string} eventType - Event type (e.g., 'click', 'input')
         * @param {Function} handler - Handler function, receives (event, matchedElement)
         * @returns {Function} Cleanup function to remove event listener
         */
        delegate: function(selector, eventType, handler) {
            const wrappedHandler = function(e) {
                const matchedElement = e.target.closest(selector);
                if (matchedElement) {
                    handler.call(matchedElement, e, matchedElement);
                }
            };

            document.addEventListener(eventType, wrappedHandler);

            // Return cleanup function
            return function() {
                document.removeEventListener(eventType, wrappedHandler);
            };
        },

        /**
         * Find element with data-lens-target attribute
         * @param {HTMLElement} element - Element to start search from
         * @param {string} targetName - Target name (value of data-lens-target)
         * @returns {HTMLElement|null} Found element or null
         */
        findTarget: function(element, targetName) {
            if (!element) return null;
            return element.closest('[data-lens-target="' + targetName + '"]');
        },

        /**
         * Find element with data-lens-control attribute
         * @param {string} controlName - Control name (value of data-lens-control)
         * @param {HTMLElement} [context=document] - Context to search within
         * @returns {HTMLElement|null} Found element or null
         */
        findControl: function(controlName, context) {
            context = context || document;
            return context.querySelector('[data-lens-control="' + controlName + '"]');
        },

        /**
         * Find element with data-lens-action attribute
         * @param {string} actionName - Action name (value of data-lens-action)
         * @param {HTMLElement} [context=document] - Context to search within
         * @returns {HTMLElement|null} Found element or null
         */
        findAction: function(actionName, context) {
            context = context || document;
            return context.querySelector('[data-lens-action="' + actionName + '"]');
        },

        /**
         * Enter edit mode pattern - hide display, show edit
         * Replaces 11+ instances of manual show/hide logic
         * @param {HTMLElement} container - Container element
         * @param {string} displayTargetName - data-lens-target for display element
         * @param {string} editTargetName - data-lens-target for edit element
         * @param {Function} [onEnter] - Optional callback after entering edit mode
         */
        enterEditMode: function(container, displayTargetName, editTargetName, onEnter) {
            if (!container) return;

            const display = container.querySelector('[data-lens-target="' + displayTargetName + '"]');
            const edit = container.querySelector('[data-lens-target="' + editTargetName + '"]');

            if (display && edit) {
                this.hide(display);
                this.show(edit);

                // Focus first input if available
                const input = edit.querySelector('input, textarea, select');
                if (input) {
                    input.focus();
                    if (input.select && input.type !== 'checkbox' && input.type !== 'radio') {
                        input.select();
                    }
                }

                if (onEnter) {
                    onEnter(edit, display);
                }
            }
        },

        /**
         * Reset Craft's form change tracker after AJAX save.
         * Prevents "Changes you made may not be saved" warning on page unload.
         * @param {HTMLElement} el - Element inside the form
         */
        resetFormBaseline: function(el) {
            var $form = $(el).closest('form[data-confirm-unload]');
            if ($form.length) {
                var serializer = $form.data('serializer');
                $form.data('initialSerializedValue', typeof serializer === 'function' ? serializer() : $form.serialize());
            }
        },

        /**
         * Exit edit mode pattern - show display, hide edit
         * @param {HTMLElement} container - Container element
         * @param {string} displayTargetName - data-lens-target for display element
         * @param {string} editTargetName - data-lens-target for edit element
         * @param {Function} [onExit] - Optional callback after exiting edit mode
         */
        exitEditMode: function(container, displayTargetName, editTargetName, onExit) {
            if (!container) return;

            const display = container.querySelector('[data-lens-target="' + displayTargetName + '"]');
            const edit = container.querySelector('[data-lens-target="' + editTargetName + '"]');

            if (display && edit) {
                this.show(display);
                this.hide(edit);

                if (onExit) {
                    onExit(display, edit);
                }
            }
        }
    };
})();
