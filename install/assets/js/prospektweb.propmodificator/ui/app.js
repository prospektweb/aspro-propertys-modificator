/**
 * prospektweb.propmodificator — композиция фронтенд-модулей.
 */
;(function () {
    'use strict';

    var PModificator = {
        init: function () {
            var cfg = window.pmodConfig;
            if (!cfg) {
                console.warn('[pmod] window.pmodConfig не определён — модуль не инициализирован');
                return;
            }

            var containers = document.querySelectorAll('.sku-props');
            if (!containers.length) return;

            containers.forEach(function (container) {
                PModificator.initContainer(container, cfg);
            });

            // После обновления SKU в Аспро повторно применяем кастомную цену,
            // чтобы "техническая" цена X-ТП не перетирала расчёт pmod.
            PModificator.hookAsproSkuFinalAction();
        },

        initContainer: function (container, cfg) {
            if (!container) return;
            if (container.dataset.pmodInitialized === 'Y') return;

            var productId = parseInt(container.dataset.itemId, 10);
            if (!productId) return;

            var productCfg = cfg.products && cfg.products[productId];
            if (!productCfg) return;

            var formatPropId = productCfg.formatPropId;
            var volumePropId = productCfg.volumePropId;
            var allPropIds   = productCfg.allPropIds || [];
            var catalogGroups = productCfg.catalogGroups || {};

            var state = {
                productId:        productId,
                offers:           productCfg.offers || [],
                formatCfg:        productCfg.formatSettings || {},
                volumeCfg:        productCfg.volumeSettings || {},
                formatPropCode:   productCfg.formatPropCode || '',
                volumePropCode:   productCfg.volumePropCode || '',
                volumeEnumMap:    productCfg.volumeEnumMap || {},
                formatEnumMap:    productCfg.formatEnumMap || {},
                skuPropsEnumMap:  productCfg.skuPropsEnumMap || {},
                customConfig:     productCfg.customConfig || {},
                skuPropCodeToId:  productCfg.skuPropCodeToId || {},
                catalogGroups:    catalogGroups,
                canBuyGroups:     productCfg.canBuyGroups || [],
                allPropIds:       allPropIds,
                roundingRules:    productCfg.roundingRules || {},
                activeOtherProps: {},
                customWidth:      null,
                customHeight:     null,
                customVolume:     null,
                customMode:       false,
                mainPriceGroupId: null,
                // AJAX state (AbortController + requestId для защиты от race conditions)
                _ajaxAbortCtrl:   null,
                _ajaxRequestId:   0,
                _volumeLabelTimer: null,
                _otherPropRecalcTimer: null,
                customValuesBySkuPropertyCode: {},
                _pendingUiUpdate: false,
                _uiRevision: 0,
                _activeUiRevision: 0,
                _uiStabilizationTimer: null,
            };

            // Регистрируем container в state для последующего re-apply после onFinalActionSKUInfo
            state.containerEl = container;

            // Найти блоки свойств
            if (formatPropId) {
                var formatInner = container.querySelector('.sku-props__inner[data-id="' + formatPropId + '"]');
                if (formatInner) {
                    PModificator.enhanceFormatProp(formatInner, state, container);
                }
            }

            var volumeInner = null;
            if (volumePropId) {
                volumeInner = container.querySelector('.sku-props__inner[data-id="' + volumePropId + '"]');
                if (volumeInner) {
                    PModificator.enhanceVolumeProp(volumeInner, state, container);
                }
            }

            // Предзаполняем инпут тиража значением из URL (?pmod_volume=N)
            if (productCfg.initialVolume && volumeInner && volumeInner._pmodVolumeInput) {
                var initVol = parseInt(productCfg.initialVolume, 10);
                if (!isNaN(initVol) && initVol > 0) {
                    volumeInner._pmodVolumeInput.value = initVol;
                    // Запускаем обработчик как будто пользователь вышел из поля
                    volumeInner._pmodVolumeInput.dispatchEvent(new Event('blur'));
                }
            }

            // Следим за кликами по стандартным кнопкам ТП
            PModificator.watchPresetClicks(container, state);

            // Нормализуем варианты для произвольных полей:
            // убираем технические значения и применяем hidePresetButtons.
            PModificator.applyCustomFieldVariantRules(container, state);

            // Считываем активный выбор "прочих" свойств после нормализации DOM.
            PModificator.rebuildActiveOtherProps(state);

            // Фиксируем активную группу цены Аспро как baseline для custom-режима.
            var detectedMainGid = PModificator.detectActivePriceGroupIdFromDom(state);
            if (detectedMainGid) {
                state.mainPriceGroupId = detectedMainGid;
            }

            // Перехватываем отправку корзины
            PModificator.hookBasket(container, state);

            PModificator.applyFinalUiState(state);

            container._pmodState = state;
            container.dataset.pmodInitialized = 'Y';
        },

        resetContainer: function (container) {
            if (!container) return;

            var state = container._pmodState;
            if (state && typeof state._destroy === 'function') {
                state._destroy();
            }

            container._pmodState = null;
            delete container.dataset.pmodInitialized;
        },

        resetAllContainers: function () {
            var containers = document.querySelectorAll('.sku-props');
            if (!containers.length) return;

            containers.forEach(function (container) {
                PModificator.resetContainer(container);
            });
        },

        requestCalcPrice: function (url, payload, signal) {
            if (!window.PModApi || typeof window.PModApi.postForm !== 'function') {
                return Promise.reject(new Error('PModApi is not available'));
            }

            return window.PModApi.postForm(url, payload, signal);
        }
    };

    [window.PModControls, window.PModPricingMain, window.PModIntegration, window.PModBasket].forEach(function (mod) {
        if (!mod) return;
        Object.keys(mod).forEach(function (key) {
            if (typeof mod[key] === 'function') {
                PModificator[key] = mod[key];
            }
        });
    });

    window.PModificator = PModificator;

    if (!window._pmodActiveStates) {
        window._pmodActiveStates = [];
    }
})();
