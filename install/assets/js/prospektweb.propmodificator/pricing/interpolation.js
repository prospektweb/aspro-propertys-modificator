/**
 * Pricing interpolation helpers module.
 */
;(function () {
    'use strict';

    function linearInterp(points, value) {
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

    function findNeighbors(sorted, value) {
        var low = sorted[0];
        var high = sorted[sorted.length - 1];
        for (var i = 0; i < sorted.length; i++) {
            if (sorted[i] <= value) low = sorted[i];
            if (sorted[i] >= value) { high = sorted[i]; break; }
        }
        return [low, high];
    }

    function bilinearInterp(offers, width, height, volume) {
        var area = width * height;
        var pts = offers.filter(function (o) {
            return o.width && o.height && o.volume && o.price;
        }).map(function (o) {
            return { area: o.width * o.height, volume: o.volume, price: o.price };
        });

        if (!pts.length) return null;

        var areas = Array.from(new Set(pts.map(function (p) { return p.area; }))).sort(function (a, b) { return a - b; });
        var volumes = Array.from(new Set(pts.map(function (p) { return p.volume; }))).sort(function (a, b) { return a - b; });

        if (areas.length < 2 || volumes.length < 2) {
            var areaPoints = areas.map(function (a) {
                var match = pts.find(function (p) { return p.area === a; });
                return { key: a, price: match ? match.price : 0 };
            });
            return linearInterp(areaPoints, area);
        }

        var areaNeighbors = findNeighbors(areas, area);
        var volumeNeighbors = findNeighbors(volumes, volume);
        var aLow = areaNeighbors[0];
        var aHigh = areaNeighbors[1];
        var vLow = volumeNeighbors[0];
        var vHigh = volumeNeighbors[1];

        function findPrice(a, v) {
            var closest = null;
            var bestDist = Infinity;
            pts.forEach(function (p) {
                var d = Math.abs(p.area - a) + Math.abs(p.volume - v);
                if (d < bestDist) { bestDist = d; closest = p.price; }
            });
            return closest;
        }

        var q11 = findPrice(aLow, vLow);
        var q12 = findPrice(aLow, vHigh);
        var q21 = findPrice(aHigh, vLow);
        var q22 = findPrice(aHigh, vHigh);
        if (q11 === null || q12 === null || q21 === null || q22 === null) return null;

        var tA = aLow === aHigh ? 0 : (area - aLow) / (aHigh - aLow);
        var r1 = q11 + tA * (q21 - q11);
        var r2 = q12 + tA * (q22 - q12);

        var tV = vLow === vHigh ? 0 : (volume - vLow) / (vHigh - vLow);
        return r1 + tV * (r2 - r1);
    }

    window.PModInterpolation = {
        linearInterp: linearInterp,
        bilinearInterp: bilinearInterp,
        findNeighbors: findNeighbors,
    };

    window.PModPricing = window.PModInterpolation;
})();
