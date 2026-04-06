/**
 * Pricing helpers module.
 */
;(function () {
    'use strict';

    function linear(points, value) {
        if (!points || !points.length) return null;
        if (value <= points[0].key) return points[0].price;
        if (value >= points[points.length - 1].key) return points[points.length - 1].price;

        for (var i = 0; i < points.length - 1; i++) {
            var lo = points[i];
            var hi = points[i + 1];
            if (value >= lo.key && value <= hi.key) {
                var t = (value - lo.key) / (hi.key - lo.key);
                return lo.price + t * (hi.price - lo.price);
            }
        }

        return points[points.length - 1].price;
    }

    window.PModPricing = {
        linearInterp: linear
    };
})();
