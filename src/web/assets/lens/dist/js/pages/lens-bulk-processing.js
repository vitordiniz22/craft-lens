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
            var statsJson = r.headers.get('X-Lens-Stats');
            return r.text().then(function(html) { return {html: html, state: state, statsJson: statsJson}; });
        })
        .then(function(result) {
            if (result.state !== 'processing') {
                clearInterval(pollTimer);
                window.Lens.utils.safeReload();
                return;
            }
            container.innerHTML = result.html;
            updateStatCards(result.statsJson);
        })
        .catch(function() {
            clearInterval(pollTimer);
            Craft.cp.displayError(Craft.t('lens', 'Failed to load progress. Please refresh the page.'));
        });
    }

    function updateStatCards(statsJson) {
        if (!statsJson) return;
        try {
            var stats = JSON.parse(statsJson);
        } catch (e) {
            return;
        }

        var cards = document.querySelectorAll('[data-lens-stat]');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var key = card.dataset.lensStat;
            if (stats[key] === undefined) continue;

            var value = stats[key];
            card.dataset.lensCount = String(value);
            var valueEl = card.querySelector('.lens-stat-value');
            if (valueEl) {
                valueEl.textContent = Number(value).toLocaleString();
            }
        }
    }
})();
