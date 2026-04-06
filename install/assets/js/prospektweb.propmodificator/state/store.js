/**
 * State module for frontend config bootstrap.
 */
;(function () {
    'use strict';

    var state = { config: null };

    window.PModStore = {
        bootstrap: function (config) {
            state.config = config;
        },
        getConfig: function () {
            return state.config || window.pmodConfig || null;
        }
    };
})();
