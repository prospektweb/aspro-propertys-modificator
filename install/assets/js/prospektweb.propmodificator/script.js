/**
 * Entry point for prospektweb.propmodificator frontend modules.
 */
;(function () {
    'use strict';

    if (window.PModStore && typeof window.PModStore.bootstrap === 'function') {
        window.PModStore.bootstrap(window.pmodConfig || null);
    }

    if (window.PModificator && typeof window.PModificator.init === 'function') {
        if (document.readyState !== 'loading') {
            window.PModificator.init();
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                window.PModificator.init();
            });
        }
    }
})();
