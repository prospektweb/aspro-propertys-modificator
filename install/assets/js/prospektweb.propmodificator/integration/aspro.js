/**
 * PModIntegration module.
 */
;(function () {
    'use strict';

    function normalizePriceCodeName(value) {
        return String(value || '')
            .replace(/\u00a0/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    var formatPrice = (window.PModUtils && window.PModUtils.formatPrice)
        ? window.PModUtils.formatPrice
        : function (price) { return String(price); };
    var hasNumberValue = (window.PModUtils && window.PModUtils.hasNumberValue)
        ? window.PModUtils.hasNumberValue
        : function (value) { return value !== null && value !== undefined; };
    var UI_STABILIZATION_TIMEOUT_MS = 1200;

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
                    if (state._uiStabilizationTimer) {
                        clearTimeout(state._uiStabilizationTimer);
                        state._uiStabilizationTimer = null;
                    }

                    // После перерисовки SKU повторно применяем фильтры вариантов
                    // (технические значения + hidePresetButtons).
                    PModificator.applyCustomFieldVariantRules(state.containerEl, state);

                    // После onFinalActionSKUInfo перечитываем активные "прочие" свойства из DOM.
                    PModificator.rebuildActiveOtherProps(state);

                    state._activeUiRevision = state._uiRevision;
                    PModificator.applyFinalUiState(state);
                });
            });
        },

        beginUiStabilization: function (state, waitForAsproEvent) {
            if (!state) return 0;
            if (state._uiStabilizationTimer) {
                clearTimeout(state._uiStabilizationTimer);
                state._uiStabilizationTimer = null;
            }
            state._uiRevision = (state._uiRevision || 0) + 1;
            state._pendingUiUpdate = true;
            PModificator.setPriceLoading(true, state);

            // Если фактической смены SKU не было (и onFinalActionSKUInfo не придёт),
            // завершаем цикл сразу по текущему состоянию DOM.
            if (waitForAsproEvent === false) {
                state._activeUiRevision = state._uiRevision;
                PModificator.applyFinalUiState(state);
            }
            // Страховка: если Aspro не вызовет onFinalActionSKUInfo, не оставляем UI в "loading".
            if (waitForAsproEvent === true) {
                var localRevision = state._uiRevision;
                state._uiStabilizationTimer = setTimeout(function () {
                    if (!PModificator.isRevisionActual(state, localRevision)) return;
                    if (!state._pendingUiUpdate) return;
                    state._activeUiRevision = state._uiRevision;
                    PModificator.applyFinalUiState(state);
                }, UI_STABILIZATION_TIMEOUT_MS);
            }

            return state._uiRevision;
        },

        registerCustomPropertyChange: function (state, didTriggerSkuSwitch) {
            return PModificator.beginUiStabilization(state, !!didTriggerSkuSwitch);
        },

        isRevisionActual: function (state, revision) {
            return !!state && revision === state._uiRevision;
        },

        setPriceLoading: function (isLoading, state) {
            var popup = PModificator.getVisiblePopupPriceElement(state);
            if (!popup) return;
            popup.classList.toggle('pmod-price-loading', !!isLoading);
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

        recomputeCustomMode: function (state) {
            state.customMode = !!(
                hasNumberValue(state.customVolume) ||
                (hasNumberValue(state.customWidth) && hasNumberValue(state.customHeight))
            );
        },

        rebuildActiveOtherProps: function (state) {
            if (!state || !state.containerEl || !Array.isArray(state.allPropIds)) {
                return {};
            }

            var rebuilt = {};
            state.allPropIds.forEach(function (pid) {
                var propId = parseInt(pid, 10);
                if (isNaN(propId)) return;
                var innerEl = state.containerEl.querySelector('.sku-props__inner[data-id="' + propId + '"]');
                if (!innerEl) return;
                var activeBtn = innerEl.querySelector('.sku-props__value--active:not(.pmod-hidden-technical-value)');
                if (!activeBtn || !activeBtn.dataset.onevalue) return;
                var enumId = parseInt(activeBtn.dataset.onevalue, 10);
                if (!isNaN(enumId)) {
                    rebuilt[propId] = enumId;
                }
            });

            state.activeOtherProps = rebuilt;
            return rebuilt;
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
            var activeOtherProps = PModificator.rebuildActiveOtherProps(state);

            var payload = {
                productId: state.productId,
                basket_qty: PModificator.getBasketQuantity(state.productId),
                active_group_id: state.mainPriceGroupId || null,
                visible_groups: [],
                other_props: activeOtherProps || null
            };

            var visibleGroups = PModificator.getVisiblePriceGroupIds(state);
            // Если удалось определить только одну группу, не сужаем серверный выбор —
            // это часто "шумный" кейс, когда Aspro отдаёт неполный PRICE_CODE.
            if (visibleGroups.length > 1) {
                payload.visible_groups = visibleGroups;
            }
            if (hasNumberValue(state.customVolume)) payload.volume = state.customVolume;
            if (hasNumberValue(state.customWidth)) payload.width = state.customWidth;
            if (hasNumberValue(state.customHeight)) payload.height = state.customHeight;

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
                    console.warn('[pmod] Server price fetch error:', {
                        message: e && e.message ? e.message : String(e),
                        status: e && e.status ? e.status : null,
                        response: e && e.response ? e.response : null,
                        responseText: e && typeof e.responseText === 'string' ? e.responseText.slice(0, 500) : null,
                        url: e && e.url ? e.url : ajaxUrl,
                    });
                    callback(null);
                });
        },

        getBasketQuantity: function (productId) {
            function isInactiveInput(input) {
                if (!input || input.tagName !== 'INPUT') return true;
                if (input.type === 'hidden' || input.disabled) return true;

                var hiddenAncestor = input.closest(
                    '[hidden], [aria-hidden="true"], .hidden, .d-none, .is-hidden, .inactive, .disabled'
                );
                if (hiddenAncestor) return true;

                if (input.offsetParent === null && input.getClientRects().length === 0) {
                    return true;
                }

                return false;
            }

            function pickQuantityFromRoot(root, selectors) {
                if (!root) return null;
                for (var i = 0; i < selectors.length; i++) {
                    var el = root.querySelector(selectors[i]);
                    if (!el || isInactiveInput(el)) continue;
                    var value = parseInt(el.value, 10);
                    if (!isNaN(value) && value > 0) return value;
                }
                return null;
            }

            var localSelectors = [
                '[name="quantity"]',
                'input[data-entity="quantity-input"]',
                '.counter__value input',
                '.counter input[type="number"]'
            ];

            var globalSelectors = [
                '.catalog-detail [name="quantity"]',
                '.catalog-detail input[data-entity="quantity-input"]',
                '.catalog-detail .counter__value input',
                '.catalog-detail .counter input[type="number"]'
            ];

            var sku = document.querySelector('.sku-props[data-item-id="' + productId + '"]');
            if (sku) {
                var cardRoot = sku.closest(
                    '.catalog-detail__main, .catalog-detail, .catalog-block, .catalog-item, [data-entity="item"]'
                ) || sku.parentElement;

                var nearValue = pickQuantityFromRoot(cardRoot, localSelectors);
                if (nearValue !== null) return nearValue;
            }

            var fallbackValue = pickQuantityFromRoot(document, globalSelectors);
            if (fallbackValue !== null) return fallbackValue;

            return 1;
        },

        getVisiblePriceGroupIds: function (state) {
            var popupPrice = PModificator.getVisiblePopupPriceElement(state);
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
                if (!g || !g.name) return;
                var normalizedName = normalizePriceCodeName(g.name);
                if (normalizedName) {
                    nameToGid[normalizedName] = gid;
                }
            });

            var gids = [];
            priceCodeOrder.forEach(function (name) {
                var gid = nameToGid[normalizePriceCodeName(name)];
                if (gid) gids.push(String(gid));
            });
            return gids;
        },

        detectActivePriceGroupIdFromDom: function (state) {
            if (!state || !state.catalogGroups) return null;

            var nameToGid = {};
            Object.keys(state.catalogGroups).forEach(function (gid) {
                var g = state.catalogGroups[gid];
                var n = g && g.name ? String(g.name).trim().toLowerCase() : '';
                if (n) nameToGid[n] = String(gid);
            });

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

            var preferredPopup = PModificator.getVisiblePopupPriceElement(state);
            var candidates = [];
            if (preferredPopup) {
                candidates.push(preferredPopup);
            }
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                if ((el.offsetParent !== null || el.offsetHeight > 0) && candidates.indexOf(el) === -1) {
                    candidates.push(el);
                }
            });

            for (var i = 0; i < candidates.length; i++) {
                var row = candidates[i].querySelector('.price--current');
                if (!row) continue;
                var title = extractTitle(row);
                if (!title) continue;
                if (nameToGid[title]) {
                    return nameToGid[title];
                }
            }

            // Жёсткий fallback по "первой видимой" группе может закрепить неверный active_group_id.
            // Если надёжно определить активную группу не удалось — возвращаем null.
            return null;
        },

        applyServerPricesToDom: function (container, interpolated, state, uiRevision) {
            if (!PModificator.isRevisionActual(state, uiRevision)) return;
            var popupPrice = PModificator.getVisiblePopupPriceElement(state);
            var popupTargets = [];
            if (popupPrice) {
                popupTargets.push(popupPrice);
            }

            // Дополнительно обновляем все видимые popup-блоки цены (например, основной + фиксированный хедер),
            // чтобы избежать рассинхрона, когда Aspro рендерит несколько зеркал цены.
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                if ((el.offsetParent !== null || el.offsetHeight > 0) && popupTargets.indexOf(el) === -1) {
                    popupTargets.push(el);
                }
            });

            if (!popupTargets.length) {
                // Fallback: обновляем собственный элемент модуля
                var basketQty = PModificator.getBasketQuantity(state.productId);
                var visibleGroupIds = PModificator.getVisiblePriceGroupIds(state);
                var mainPrice = PModificator.getMainPrice(
                    interpolated,
                    state.catalogGroups,
                    state.canBuyGroups,
                    state.mainPriceGroupId,
                    basketQty,
                    visibleGroupIds
                );
                if (mainPrice === null) return;
                var priceEl = container.querySelector('.pmod-custom-price');
                if (priceEl && priceEl.style.display !== 'none') {
                    priceEl.querySelector('.pmod-custom-price__value').textContent = formatPrice(mainPrice);
                }
                return;
            }

            popupTargets.forEach(function (target) {
                // Отменяем ожидающий клиентский апдейт и сразу применяем серверные данные
                PModificator.cancelPendingPriceUpdate(target);
                target._pmodUpdating = true;
                PModificator.applyPricesToDom(target, interpolated, state);
                target._pmodUpdating = false;
                target.classList.remove('pmod-price-loading');
            });
        }

    };
})();
