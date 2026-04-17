/**
 * API module for calculator requests.
 */
;(function () {
    'use strict';

    function normalizeInt(value) {
        var n = parseInt(value, 10);
        return isNaN(n) ? null : n;
    }

    function normalizeArrayInts(value) {
        if (!Array.isArray(value)) return [];

        var out = [];
        value.forEach(function (item) {
            var n = normalizeInt(item);
            if (n !== null) out.push(n);
        });

        return out;
    }

    function normalizeOtherProps(value) {
        if (!value || typeof value !== 'object') return null;

        var out = {};
        Object.keys(value).forEach(function (key) {
            var propId = normalizeInt(key);
            var propValue = normalizeInt(value[key]);
            if (propId === null || propValue === null) return;
            out[String(propId)] = propValue;
        });

        return Object.keys(out).length ? out : null;
    }

    function normalizePayload(payload) {
        var src = payload || {};
        var out = {};
        Object.keys(src).forEach(function (key) {
            out[key] = src[key];
        });

        if (Object.prototype.hasOwnProperty.call(src, 'active_group_id')) {
            out.active_group_id = normalizeInt(src.active_group_id);
        }
        if (Object.prototype.hasOwnProperty.call(src, 'visible_groups')) {
            out.visible_groups = normalizeArrayInts(src.visible_groups);
        }
        if (Object.prototype.hasOwnProperty.call(src, 'other_props')) {
            out.other_props = normalizeOtherProps(src.other_props);
        }

        return out;
    }

    function appendPayloadToFormData(fd, payload) {
        Object.keys(payload).forEach(function (key) {
            var value = payload[key];
            if (value === null || typeof value === 'undefined' || value === '') return;

            if (key === 'visible_groups') {
                value.forEach(function (gid) {
                    fd.append('visible_groups[]', String(gid));
                });
                return;
            }

            if (key === 'other_props' && value && typeof value === 'object') {
                Object.keys(value).forEach(function (propId) {
                    fd.append('other_props[' + propId + ']', String(value[propId]));
                });
                return;
            }

            fd.append(key, String(value));
        });
    }

    function toUrlSearchParams(payload) {
        var params = new URLSearchParams();
        appendPayloadToFormData(params, payload);
        return params;
    }

    window.PModApi = {
        normalizePayload: normalizePayload,

        postForm: function (url, payload, signal, useUrlEncoded) {
            var normalizedPayload = normalizePayload(payload);
            var body = useUrlEncoded ? toUrlSearchParams(normalizedPayload) : new FormData();
            if (!useUrlEncoded) {
                appendPayloadToFormData(body, normalizedPayload);
            }

            return fetch(url, {
                method: 'POST',
                body: body,
                signal: signal,
                credentials: 'same-origin'
            }).then(function (res) {
                return res.text().then(function (text) {
                    var data = null;
                    if (text) {
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            data = null;
                        }
                    }

                    if (!res.ok) {
                        var message = data && data.error
                            ? data.error
                            : ('Request failed with status ' + res.status);
                        var error = new Error(message);
                        error.status = res.status;
                        error.response = data;
                        error.responseText = text || '';
                        error.url = res.url || '';
                        throw error;
                    }

                    if (data === null) {
                        throw new Error('Invalid JSON response');
                    }

                    return data;
                });
            });
        },

        postUrlEncoded: function (url, payload, signal) {
            return this.postForm(url, payload, signal, true);
        },

        // Deprecated alias for backward compatibility.
        postJson: function (url, payload, signal) {
            return this.postUrlEncoded(url, payload, signal);
        }
    };
})();
