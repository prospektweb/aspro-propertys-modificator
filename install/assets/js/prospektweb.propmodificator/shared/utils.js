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
        syncUrlPmodPropertyValue('volume', volume != null && volume !== '' ? [volume] : null);
    }

    function syncUrlPmodPropertyValue(propertyCode, values) {
        if (!window.history || !window.history.replaceState) return;
        var code = String(propertyCode || '').trim().toLowerCase();
        if (!code) return;

        var url = new URL(window.location.href);
        var params = url.searchParams;
        var key = 'pmod_' + code;
        var normalized = [];

        if (Array.isArray(values)) {
            normalized = values.map(function (value) {
                if (value === null || value === undefined) return '';
                return String(value).trim();
            }).filter(function (value) {
                return value !== '';
            });
        } else {
            var single = values === null || values === undefined ? '' : String(values).trim();
            if (single !== '') {
                normalized = [single];
            }
        }

        if (normalized.length) {
            params.set(key, normalized.join('x'));
        } else {
            params.delete(key);
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
        syncUrlPmodPropertyValue: syncUrlPmodPropertyValue,
        hasNumberValue: hasNumberValue,
    };
})();
