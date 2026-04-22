;(function () {
    'use strict';

    var hiddenIds = Array.isArray(window.pmodListingHiddenValueIds)
        ? window.pmodListingHiddenValueIds.map(function (id) { return String(parseInt(id, 10)); })
        : [];
    if (!hiddenIds.length) {
        return;
    }

    var hiddenSet = {};
    hiddenIds.forEach(function (id) {
        if (id && id !== 'NaN') {
            hiddenSet[id] = true;
        }
    });

    function hideElements(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }

        var values = root.querySelectorAll('[data-onevalue]');
        values.forEach(function (node) {
            var valueId = String(node.getAttribute('data-onevalue') || '').trim();
            if (!valueId || !hiddenSet[valueId]) {
                return;
            }

            var item = node.closest('.line-block__item') || node;
            item.style.display = 'none';
            item.setAttribute('aria-hidden', 'true');
            item.classList.add('pmod-hidden-technical-value');
        });
    }

    function init() {
        if (window.pmodConfig && window.pmodConfig.products) {
            return;
        }

        hideElements(document);
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node && node.nodeType === 1) {
                        hideElements(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
