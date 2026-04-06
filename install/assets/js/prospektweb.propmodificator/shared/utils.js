/**
 * Shared frontend utilities.
 */
;(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    function clamp(val, min, max) {
        return Math.max(min, Math.min(max, val));
    }

    function syncUrlPmodVolume(volume) {
        if (!window.history || !window.history.replaceState) return;
        var url    = new URL(window.location.href);
        var params = url.searchParams;
        if (volume != null && volume !== 0 && volume !== '') {
            params.set('pmod_volume', volume);
        } else {
            params.delete('pmod_volume');
        }
        window.history.replaceState(null, '', url.toString());
    }


    function hasNumberValue(value) {
        return value !== null && value !== undefined;
    }

    function formatPrice(price) {
        var isInt = price % 1 === 0;
        return price.toLocaleString('ru-RU', {
            minimumFractionDigits: isInt ? 0 : 2,
            maximumFractionDigits: isInt ? 0 : 2,
        }) + ' ₽';
    }

    window.PModUtils = {
        ready: ready,
        debounce: debounce,
        clamp: clamp,
        formatPrice: formatPrice,
        syncUrlPmodVolume: syncUrlPmodVolume,
        hasNumberValue: hasNumberValue,
    };
})();
