/**
 * Lens Plugin - Settings Page
 * Injects an "All" toggle into the Volumes to Process checkbox list.
 * Clicking it checks/unchecks every volume. Unchecking any volume unchecks "All".
 */
(function() {
    'use strict';

    var NAME = 'settings[enabledVolumes][]';
    var TOGGLE_ATTR = 'data-lens-all-toggle';

    function apply() {
        var firstOption = document.querySelector('input[type="checkbox"][name="' + NAME + '"]');
        if (!firstOption) return;

        var fieldset = firstOption.closest('.checkbox-select') || firstOption.closest('fieldset');
        if (!fieldset) return;
        if (fieldset.querySelector('[' + TOGGLE_ATTR + ']')) return;

        var options = Array.prototype.slice.call(
            fieldset.querySelectorAll('input[type="checkbox"][name="' + NAME + '"]')
        );

        options.forEach(function(cb) {
            cb.disabled = false;
            cb.removeAttribute('disabled');
        });

        var id = 'lens-enabled-volumes-all';
        var row = document.createElement('div');
        row.className = 'checkbox-select-item all';

        var input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'checkbox';
        input.id = id;
        input.setAttribute(TOGGLE_ATTR, '');

        var label = document.createElement('label');
        label.setAttribute('for', id);
        var strong = document.createElement('b');
        strong.textContent = Craft.t('lens', 'All');
        label.appendChild(strong);

        row.appendChild(input);
        row.appendChild(label);
        fieldset.insertBefore(row, fieldset.firstChild);

        function syncAll() {
            input.checked = options.length > 0 && options.every(function(cb) {
                return cb.checked;
            });
        }
        syncAll();

        input.addEventListener('change', function() {
            options.forEach(function(cb) {
                cb.checked = input.checked;
            });
        });

        options.forEach(function(cb) {
            cb.addEventListener('change', syncAll);
        });
    }

    if (window.Lens && Lens.utils && Lens.utils.onReady) {
        Lens.utils.onReady(apply);
    } else if (document.readyState !== 'loading') {
        apply();
    } else {
        document.addEventListener('DOMContentLoaded', apply);
    }
    window.addEventListener('load', apply);
})();
