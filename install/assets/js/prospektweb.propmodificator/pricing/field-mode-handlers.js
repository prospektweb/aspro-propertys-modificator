/**
 * Frontend field mode handlers.
 *
 * @typedef {Object} FieldModeHandlerInterface
 * @property {string} mode
 * @property {string} skuPropertyCode
 * @property {(state: Object) => boolean} hasCustomInput
 * @property {(offer: Object) => number|null} getLinearKey
 * @property {(state: Object) => number|null} getRequestedKey
 */
;(function () {
    'use strict';

    var hasNumberValue = (window.PModUtils && window.PModUtils.hasNumberValue)
        ? window.PModUtils.hasNumberValue
        : function (value) { return value !== null && value !== undefined; };

    function createFormatHandler() {
        return {
            mode: 'format',
            skuPropertyCode: 'CALC_PROP_FORMAT',
            hasCustomInput: function (state) {
                return !!(state && hasNumberValue(state.customWidth) && hasNumberValue(state.customHeight));
            },
            getLinearKey: function (offer) {
                if (!offer || !offer.width || !offer.height) return null;
                return offer.width * offer.height;
            },
            getRequestedKey: function (state) {
                if (!state || !hasNumberValue(state.customWidth) || !hasNumberValue(state.customHeight)) return null;
                return state.customWidth * state.customHeight;
            },
        };
    }

    function createVolumeHandler() {
        return {
            mode: 'volume',
            skuPropertyCode: 'CALC_PROP_VOLUME',
            hasCustomInput: function (state) {
                return !!(state && hasNumberValue(state.customVolume));
            },
            getLinearKey: function (offer) {
                return offer && offer.volume ? offer.volume : null;
            },
            getRequestedKey: function (state) {
                return state && hasNumberValue(state.customVolume) ? state.customVolume : null;
            },
        };
    }

    window.PModFieldModeHandlers = {
        format: createFormatHandler(),
        volume: createVolumeHandler(),
    };
})();
