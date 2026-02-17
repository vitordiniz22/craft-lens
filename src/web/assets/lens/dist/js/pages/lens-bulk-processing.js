/**
 * Lens Plugin - Bulk Processing Progress Polling
 * Only active during the processing state — polls for progress HTML updates.
 */
(function() {
    'use strict';

    var container = document.querySelector('[data-lens-target="progress-container"]');
    if (!container) return;

    var pollTimer = setInterval(poll, 5000);
    poll();

    function poll() {
        fetch(Craft.getCpUrl('lens/bulk/progress'), {
            headers: {
                'Accept': 'text/html',
                'X-CSRF-Token': Craft.csrfTokenValue,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(function(r) {
            var state = r.headers.get('X-Lens-State');
            return r.text().then(function(html) { return {html: html, state: state}; });
        })
        .then(function(result) {
            if (result.state !== 'processing') {
                clearInterval(pollTimer);
                window.location.reload();
                return;
            }
            container.innerHTML = result.html;
        })
        .catch(function() {});
    }
})();
