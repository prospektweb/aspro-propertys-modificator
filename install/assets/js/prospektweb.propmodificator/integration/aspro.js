/**
 * PModIntegration module.
 */
;(function () {
    'use strict';

    var formatPrice = (window.PModUtils && window.PModUtils.formatPrice)
        ? window.PModUtils.formatPrice
        : function (price) { return String(price); };

    window.PModIntegration = {
        hookAsproSkuFinalAction: function () {
            if (window._pmodAsproFinalActionHooked) {
                return;
            }
            window._pmodAsproFinalActionHooked = true;

            if (typeof BX === 'undefined' || typeof BX.addCustomEvent !== 'function') {
                return;
            }

            BX.addCustomEvent('onFinalActionSKUInfo', function (eventdata) {
                var wrapperEl = null;
                if (eventdata && eventdata.wrapper) {
                    // В Аспро wrapper обычно jQuery-объект
                    if (eventdata.wrapper.jquery && eventdata.wrapper[0]) {
                        wrapperEl = eventdata.wrapper[0];
                    } else if (eventdata.wrapper.nodeType === 1) {
                        wrapperEl = eventdata.wrapper;
                    }
                }

                var states = window._pmodActiveStates || [];
                states.forEach(function (state) {
                    if (!state || !state.containerEl) return;

                    // Если wrapper известен — применяем только к затронутому контейнеру
                    if (wrapperEl && !wrapperEl.contains(state.containerEl) && wrapperEl !== state.containerEl) {
                        return;
                    }

                    if (!state._pendingUiUpdate) return;

                    state.rawBaseTitleFromAspro = PModificator.getCurrentRawH1Text() || '';
                    state.renderedCustomTitle = PModificator.refreshH1ByCustomConfig(
                        state.containerEl,
                        state,
                        state.rawBaseTitleFromAspro
                    );
                    state._activeUiRevision = state._uiRevision;
                    PModificator.applyFinalUiState(state);
                });
            });
        },

        setupH1TitleSync: function () {
            var h1 = PModificator.getH1Element();
            if (!h1) return;

            // Первичная синхронизация
            if (h1.textContent.trim()) {
                h1.title = h1.textContent.trim();
            }

            var obs = new MutationObserver(function () {
                if (h1._pmodUpdatingTitle) return;
                var text = h1.textContent.trim();
                if (text && h1.title !== text) {
                    h1.title = text;
                }
            });
            obs.observe(h1, { childList: true, characterData: true, subtree: true });
        },

        getH1Element: function () {
            var h1 = document.querySelector('h1.pmod-title-clamp');
            if (!h1) h1 = document.querySelector('h1');
            return h1;
        },

        getCurrentRawH1Text: function () {
            var h1 = PModificator.getH1Element();
            return h1 ? h1.textContent.trim() : '';
        },

        beginUiStabilization: function (state, waitForAsproEvent) {
            if (!state) return 0;
            state._uiRevision = (state._uiRevision || 0) + 1;
            state._pendingUiUpdate = true;
            PModificator.setTitleLoading(true);
            PModificator.setPriceLoading(true);

            // Если фактической смены SKU не было (и onFinalActionSKUInfo не придёт),
            // завершаем цикл сразу по текущему состоянию DOM.
            if (waitForAsproEvent === false) {
                state.rawBaseTitleFromAspro = PModificator.getCurrentRawH1Text() || '';
                state.renderedCustomTitle = PModificator.refreshH1ByCustomConfig(
                    state.containerEl,
                    state,
                    state.rawBaseTitleFromAspro
                );
                state._activeUiRevision = state._uiRevision;
                PModificator.applyFinalUiState(state);
            }

            return state._uiRevision;
        },

        registerCustomPropertyChange: function (state, didTriggerSkuSwitch) {
            return PModificator.beginUiStabilization(state, !!didTriggerSkuSwitch);
        },

        isRevisionActual: function (state, revision) {
            return !!state && revision === state._uiRevision;
        },

        setTitleLoading: function (isLoading) {
            var h1 = PModificator.getH1Element();
            if (!h1) return;
            h1.classList.toggle('pmod-title-loading', !!isLoading);
        },

        setPriceLoading: function (isLoading) {
            var popup = PModificator.getVisiblePopupPriceElement();
            if (!popup) return;
            popup.classList.toggle('pmod-price-loading', !!isLoading);
        },

        refreshH1ByCustomConfig: function (container, state, rawBaseTitleFromAspro) {
            if (!state || !state.customConfig || !Array.isArray(state.customConfig.fields)) return;
            var newText = String(rawBaseTitleFromAspro || '').trim();
            if (!newText) return '';
            var self = this;

            state.customConfig.fields.forEach(function (field) {
                var skuCode = String(field && field.binding && field.binding.skuPropertyCode || '').trim();
                if (!skuCode) return;
                var replaceKeys = Array.isArray(field.replaceKeys) ? field.replaceKeys : [];
                if (!replaceKeys.length) return;

                var fallbackParts = self.getDisplayValueParts(container, state, skuCode, replaceKeys.length);
                replaceKeys.forEach(function (rk, idx) {
                    var key = String(rk && rk.key || '').trim();
                    if (!key) return;
                    var customVal = self.getCustomValueByIndex(state, skuCode, idx);
                    var replacement = customVal !== null && customVal !== undefined && customVal !== ''
                        ? String(customVal)
                        : (fallbackParts[idx] !== undefined ? String(fallbackParts[idx]) : '');
                    newText = newText.split(key).join(replacement);
                });
            });

            return newText;
        },

        getCustomValueByIndex: function (state, skuCode, inputIdx) {
            var map = state.customValuesBySkuPropertyCode || {};
            var bucket = map[skuCode];
            if (bucket === null || bucket === undefined) return null;
            if (typeof bucket === 'object') {
                if (bucket[inputIdx] !== undefined && bucket[inputIdx] !== null && bucket[inputIdx] !== '') {
                    return bucket[inputIdx];
                }
                return null;
            }
            return inputIdx === 0 ? bucket : null;
        },

        setCustomValuesForSkuCode: function (state, skuCode, values) {
            if (!state.customValuesBySkuPropertyCode) {
                state.customValuesBySkuPropertyCode = {};
            }
            if (!values || !values.length) {
                delete state.customValuesBySkuPropertyCode[skuCode];
                return;
            }
            var valueMap = {};
            values.forEach(function (val, idx) {
                if (val !== null && val !== undefined && val !== '') {
                    valueMap[idx] = String(val);
                }
            });
            if (!Object.keys(valueMap).length) {
                delete state.customValuesBySkuPropertyCode[skuCode];
                return;
            }
            state.customValuesBySkuPropertyCode[skuCode] = valueMap;
        },

        getDisplayValueParts: function (container, state, skuCode, count) {
            // Для тиража приоритет отдаем текущему лейблу в заголовке свойства
            // (он отражает реальное значение в инпуте даже если активная кнопка ТП — "X"/"Другое количество").
            if (state && state.volumePropCode && String(skuCode) === String(state.volumePropCode)) {
                var volumePropId = state.skuPropCodeToId && state.skuPropCodeToId[skuCode];
                if (volumePropId) {
                    var volumeInner = container.querySelector('.sku-props__inner[data-id="' + volumePropId + '"]');
                    if (volumeInner) {
                        var volumeLabel = volumeInner.querySelector('.sku-props__title .sku-props__js-size');
                        var volumeText = volumeLabel ? String(volumeLabel.textContent || '').trim() : '';
                        if (volumeText) {
                            return [volumeText];
                        }
                    }
                }
            }

            var raw = PModificator.getCurrentSkuDisplayValue(container, state, skuCode);
            if (!raw) return new Array(count).fill('');
            var compact = String(raw).trim();
            if (count <= 1) return [compact];

            var parts = compact.split(/[xх×]/i).map(function (p) { return p.trim(); }).filter(Boolean);
            if (parts.length >= count) return parts.slice(0, count);

            var numbers = compact.match(/[\d]+(?:[.,]\d+)?/g);
            if (numbers && numbers.length >= count) return numbers.slice(0, count);

            var out = [];
            for (var i = 0; i < count; i++) {
                out[i] = parts[i] || ((numbers && numbers[i]) ? numbers[i] : compact);
            }
            return out;
        },

        getCurrentSkuDisplayValue: function (container, state, skuCode) {
            var map = state.skuPropCodeToId || {};
            var propId = map[skuCode];
            if (!propId) return '';
            var inner = container.querySelector('.sku-props__inner[data-id="' + propId + '"]');
            if (!inner) return '';
            var activeBtn = inner.querySelector('.sku-props__value--active') || inner.querySelector('.sku-props__value');
            if (!activeBtn) return '';
            return String(activeBtn.dataset.title || activeBtn.textContent || '').trim();
        },

        recomputeCustomMode: function (state) {
            state.customMode = !!(
                state.customVolume !== null ||
                (state.customWidth !== null && state.customHeight !== null)
            );
        },

        fetchServerPrice: function (state, uiRevision, callback) {
            var cfg     = window.pmodConfig;
            var ajaxUrl = cfg && cfg.ajaxUrl;
            if (!ajaxUrl) {
                callback(null);
                return;
            }

            // Отменяем предыдущий запрос
            if (state._ajaxAbortCtrl) {
                state._ajaxAbortCtrl.abort();
                state._ajaxAbortCtrl = null;
            }

            var requestId = ++state._ajaxRequestId;
            var abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
            state._ajaxAbortCtrl = abortCtrl;

            var payload = {
                productId: state.productId,
                basket_qty: PModificator.getBasketQuantity(state.productId),
                active_group_id: state.mainPriceGroupId || null,
                visible_groups: [],
                other_props: state.activeOtherProps || null
            };

            var visibleGroups = PModificator.getVisiblePriceGroupIds(state);
            // Если удалось определить только одну группу, не сужаем серверный выбор —
            // это часто "шумный" кейс, когда Aspro отдаёт неполный PRICE_CODE.
            if (visibleGroups.length > 1) {
                payload.visible_groups = visibleGroups;
            }
            if (state.customVolume)  payload.volume = state.customVolume;
            if (state.customWidth)   payload.width = state.customWidth;
            if (state.customHeight)  payload.height = state.customHeight;

            // CSRF-токен Bitrix
            var sessid = (typeof BX !== 'undefined' && BX.bitrix_sessid)
                ? BX.bitrix_sessid()
                : ((typeof window.bitrix_sessid !== 'undefined') ? window.bitrix_sessid : '');
            payload.sessid = sessid || '';

            PModificator.requestCalcPrice(ajaxUrl, payload, abortCtrl ? abortCtrl.signal : null)
                .then(function (data) {
                    // Игнорируем устаревший ответ (защита от race conditions)
                    if (state._ajaxRequestId !== requestId) return;
                    if (!PModificator.isRevisionActual(state, uiRevision)) return;
                    state._ajaxAbortCtrl = null;
                    callback(data);
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    if (!PModificator.isRevisionActual(state, uiRevision)) return;
                    console.warn('[pmod] Server price fetch error:', e);
                    callback(null);
                });
        },

        getBasketQuantity: function (productId) {
            var selectors = [
                '.catalog-detail [name="quantity"]',
                '.catalog-detail input[data-entity="quantity-input"]',
                '.catalog-detail .counter__value input',
                '.catalog-detail .counter input[type="number"]',
            ];

            for (var i = 0; i < selectors.length; i++) {
                var el = document.querySelector(selectors[i]);
                if (!el) continue;
                var v = parseInt(el.value, 10);
                if (!isNaN(v) && v > 0) return v;
            }

            var sku = document.querySelector('.sku-props[data-item-id="' + productId + '"]');
            if (sku) {
                var nearQty = sku.closest('.catalog-detail__main') &&
                    sku.closest('.catalog-detail__main').querySelector('[name="quantity"], input[data-entity="quantity-input"]');
                if (nearQty) {
                    var nearVal = parseInt(nearQty.value, 10);
                    if (!isNaN(nearVal) && nearVal > 0) return nearVal;
                }
            }

            return 1;
        },

        getVisiblePriceGroupIds: function (state) {
            var popupPrice = null;
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                if (el.offsetParent !== null || el.offsetHeight > 0) {
                    popupPrice = el;
                }
            });
            if (!popupPrice) return [];

            var priceCodeOrder = null;
            try {
                var cfgAttr = popupPrice.getAttribute('data-price-config');
                if (cfgAttr) {
                    var cfg = JSON.parse(cfgAttr);
                    priceCodeOrder = (cfg && cfg.PRICE_CODE) || null;
                }
            } catch (e) {}

            if (!priceCodeOrder || !priceCodeOrder.length) return [];

            var nameToGid = {};
            Object.keys(state.catalogGroups || {}).forEach(function (gid) {
                var g = state.catalogGroups[gid];
                if (g && g.name) nameToGid[g.name] = gid;
            });

            var gids = [];
            priceCodeOrder.forEach(function (name) {
                var gid = nameToGid[name];
                if (gid) gids.push(String(gid));
            });
            return gids;
        },

        detectActivePriceGroupIdFromDom: function (state) {
            var popupPrice = PModificator.getVisiblePopupPriceElement();
            if (!popupPrice || !state || !state.catalogGroups) return null;

            var nameToGid = {};
            Object.keys(state.catalogGroups).forEach(function (gid) {
                var g = state.catalogGroups[gid];
                var n = g && g.name ? String(g.name).trim().toLowerCase() : '';
                if (n) nameToGid[n] = String(gid);
            });

            var row = popupPrice.querySelector('.price--current');
            if (!row) return null;

            function extractTitle(el) {
                var cur = el;
                while (cur) {
                    var tEl = cur.querySelector('.price__title');
                    if (tEl) {
                        var t = (tEl.textContent || '').trim().toLowerCase();
                        if (t) return t;
                    }
                    cur = cur.previousElementSibling;
                }
                return '';
            }

            var title = extractTitle(row);
            if (!title) return null;
            return nameToGid[title] || null;
        },

        applyServerPricesToDom: function (container, interpolated, state, uiRevision) {
            if (!PModificator.isRevisionActual(state, uiRevision)) return;
            var popupPrice = null;
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                if (el.offsetParent !== null || el.offsetHeight > 0) {
                    popupPrice = el;
                }
            });

            if (!popupPrice) {
                // Fallback: обновляем собственный элемент модуля
                var mainPrice = PModificator.getMainPrice(
                    interpolated,
                    state.catalogGroups,
                    state.canBuyGroups,
                    state.mainPriceGroupId
                );
                if (mainPrice === null) return;
                var priceEl = container.querySelector('.pmod-custom-price');
                if (priceEl && priceEl.style.display !== 'none') {
                    priceEl.querySelector('.pmod-custom-price__value').textContent = formatPrice(mainPrice);
                }
                return;
            }

            // Отменяем ожидающий клиентский апдейт и сразу применяем серверные данные
            PModificator.cancelPendingPriceUpdate(popupPrice);
            popupPrice._pmodUpdating = true;
            PModificator.applyPricesToDom(popupPrice, interpolated, state);
            popupPrice._pmodUpdating = false;
            popupPrice.classList.remove('pmod-price-loading');
        }

    };
})();
