/**
 * PModBasket module.
 */
;(function () {
    'use strict';

    window.PModBasket = {
        hookBasket: function (container, state) {
            // Подход 1: патч JItemActionBasket.prototype.collectRequestData
            // (работает если класс загружен, Aspro Premier >= 2.x)
            var attempts = 0;

            function tryHook() {
                attempts++;

                if (typeof JItemActionBasket !== 'undefined' &&
                    typeof JItemActionBasket.prototype.collectRequestData === 'function') {
                    PModificator.patchCollectRequestData(state);
                    return;
                }

                if (attempts < 20) {
                    setTimeout(tryHook, 300);
                }
            }

            tryHook();

            // Подход 2: перехват fetch() — надёжный fallback для любой версии Aspro.
            // Срабатывает ВСЕГДА при POST на /ajax/item.php, независимо от внутренней
            // архитектуры Aspro. Дублирование полей исключается проверкой has().
            PModificator.hookBasketViaFetch(state);
        },

        patchCollectRequestData: function (state) {
            if (JItemActionBasket.prototype._pmodPatched) return;
            JItemActionBasket.prototype._pmodPatched = true;

            var original = JItemActionBasket.prototype.collectRequestData;

            JItemActionBasket.prototype.collectRequestData = async function () {
                var formData = await original.call(this);

                if (!state.customMode) return formData;

                var containerId = parseInt(
                    (this.itemNode && this.itemNode.closest('.sku-props') &&
                     this.itemNode.closest('.sku-props').dataset.itemId) || '0',
                    10
                );

                if (containerId && containerId !== state.productId) return formData;

                PModificator.rebuildActiveOtherProps(state);

                if (state.customWidth)  formData.append('prospekt_calc[width]',  state.customWidth);
                if (state.customHeight) formData.append('prospekt_calc[height]', state.customHeight);
                if (state.customVolume) formData.append('prospekt_calc[volume]', state.customVolume);

                formData.append('prospekt_calc[is_custom]',  'Y');
                formData.append('prospekt_calc[product_id]', state.productId);

                // Передаём активные «прочие» свойства для серверной фильтрации
                if (state.activeOtherProps) {
                    Object.keys(state.activeOtherProps).forEach(function (propId) {
                        formData.append(
                            'prospekt_calc[other_props][' + propId + ']',
                            state.activeOtherProps[propId]
                        );
                    });
                }

                if (state.lastCalculatedPrice) {
                    formData.append('prospekt_calc[custom_price]', state.lastCalculatedPrice.toFixed(2));
                }

                return formData;
            };
        },

        hookBasketViaFetch: function (state) {
            // Регистрируем state в глобальном реестре
            if (!window._pmodActiveStates) {
                window._pmodActiveStates = [];
            }
            window._pmodActiveStates.push(state);

            // Устанавливаем перехватчик один раз
            if (window._pmodFetchHooked) {
                return;
            }
            window._pmodFetchHooked = true;

            if (typeof window.fetch !== 'function') {
                return; // fetch не поддерживается — пропускаем
            }

            var origFetch = window.fetch;

            function parsePositiveInt(value) {
                var n = parseInt(value, 10);
                return isNaN(n) || n <= 0 ? null : n;
            }

            function getProductIdFromFormData(fd) {
                if (!fd || typeof fd.get !== 'function') return null;
                var keys = [
                    'product_id',
                    'PRODUCT_ID',
                    'item',
                    'id',
                    'offer',
                    'offer_id',
                    'basket_props[PRODUCT_ID]',
                ];
                for (var i = 0; i < keys.length; i++) {
                    var val = fd.get(keys[i]);
                    var parsed = parsePositiveInt(val);
                    if (parsed) return parsed;
                }
                return null;
            }

            function findMatchingState(states, requestProductId) {
                var customStates = (states || []).filter(function (s) { return s && s.customMode; });
                if (!customStates.length) return null;
                if (requestProductId) {
                    for (var i = 0; i < customStates.length; i++) {
                        if (customStates[i].productId === requestProductId) {
                            return customStates[i];
                        }
                    }
                    return null;
                }
                return customStates.length === 1 ? customStates[0] : null;
            }

            window.fetch = function (url, options) {
                var urlStr = typeof url === 'string' ? url
                    : (url instanceof Request ? url.url : '');

                if (urlStr.indexOf('/ajax/item.php') !== -1) {
                    if (options && options.body instanceof FormData) {
                        var fd = options.body;
                        var requestProductId = getProductIdFromFormData(fd);
                        var states = window._pmodActiveStates || [];
                        var activeState = findMatchingState(states, requestProductId);

                        // Пропускаем если patchCollectRequestData уже добавил поля
                        if (activeState && typeof fd.has === 'function' && !fd.has('prospekt_calc[is_custom]')) {
                            PModificator.rebuildActiveOtherProps(activeState);
                            if (activeState.customWidth)  fd.append('prospekt_calc[width]',  activeState.customWidth);
                            if (activeState.customHeight) fd.append('prospekt_calc[height]', activeState.customHeight);
                            if (activeState.customVolume) fd.append('prospekt_calc[volume]', activeState.customVolume);
                            fd.append('prospekt_calc[is_custom]',  'Y');
                            fd.append('prospekt_calc[product_id]', activeState.productId);
                            if (activeState.activeOtherProps) {
                                Object.keys(activeState.activeOtherProps).forEach(function (pid) {
                                    fd.append('prospekt_calc[other_props][' + pid + ']', activeState.activeOtherProps[pid]);
                                });
                            }
                            if (activeState.lastCalculatedPrice) {
                                fd.append('prospekt_calc[custom_price]', activeState.lastCalculatedPrice.toFixed(2));
                            }
                        }
                    }
                }

                return origFetch.apply(this, arguments);
            };
        }

    };
})();
