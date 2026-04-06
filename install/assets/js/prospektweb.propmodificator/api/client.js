/**
 * API module for calculator requests.
 */
;(function () {
    'use strict';

    window.PModApi = {
        postJson: function (url, payload, signal) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {}),
                signal: signal
            }).then(function (res) { return res.json(); });
        }
    };
})();
