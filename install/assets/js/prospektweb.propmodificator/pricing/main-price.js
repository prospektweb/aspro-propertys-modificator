/**
 * PModPricingMain module.
 */
;(function () {
    'use strict';

    function applyBitrixRounding(price, rules) {
        if (!rules || !rules.length) return price;
        var rule = null;
        for (var i = 0; i < rules.length; i++) {
            if (price >= rules[i].price && (!rule || rules[i].price >= rule.price)) {
                rule = rules[i];
            }
        }
        if (!rule) return price;
        var precision = rule.precision;
        switch (rule.type) {
            case 1: return Math.floor(price / precision) * precision;  // ROUND_DOWN
            case 2: return Math.round(price / precision) * precision;  // ROUND_MATH
            case 3: return Math.ceil(price / precision) * precision;   // ROUND_UP
            default: return price;
        }
    }

    var PRICE_UPDATE_TIMEOUT_MS = 400;

    var formatPrice = (window.PModUtils && window.PModUtils.formatPrice)
        ? window.PModUtils.formatPrice
        : function (price) { return String(price); };

    window.PModPricingMain = {
        filterOffersByProps: function (offers, activeOtherProps) {
            if (!activeOtherProps || Object.keys(activeOtherProps).length === 0) {
                return offers;
            }
            return offers.filter(function (offer) {
                if (!offer.props) return false;
                var propIds = Object.keys(activeOtherProps);
                for (var i = 0; i < propIds.length; i++) {
                    var pid = propIds[i];
                    if (offer.props[pid] !== activeOtherProps[pid]) return false;
                }
                return true;
            });
        },

        interpolateAllPrices: function (offers, volume, roundingRules) {
            // Берём только ТП с реальным числовым тиражом и ценами
            // (volume === null у X-ТП, > 0 исключает потенциальные нулевые цены)
            var validOffers = offers.filter(function (o) {
                return typeof o.volume === 'number' && o.volume > 0
                    && o.prices && Object.keys(o.prices).length > 0;
            });

            if (!validOffers.length) return {};

            // Собираем все уникальные groupId
            var groupIds = {};
            validOffers.forEach(function (o) {
                Object.keys(o.prices).forEach(function (gid) { groupIds[gid] = true; });
            });

            var result = {};

            Object.keys(groupIds).forEach(function (gid) {
                // Строим карту ключей диапазонов
                var rangeMap = {};

                validOffers.forEach(function (offer) {
                    if (!offer.prices[gid]) return;
                    offer.prices[gid].forEach(function (entry) {
                        // Пропускаем плейсхолдерные цены (≤ 0)
                        if (entry.price <= 0) return;
                        var rangeKey = (entry.from === null ? '' : entry.from) + '-' + (entry.to === null ? '' : entry.to);
                        if (!rangeMap[rangeKey]) {
                            rangeMap[rangeKey] = { from: entry.from, to: entry.to, points: [] };
                        }
                        rangeMap[rangeKey].points.push({ key: offer.volume, price: entry.price });
                    });
                });

                var ranges = [];
                Object.keys(rangeMap).forEach(function (rangeKey) {
                    var range = rangeMap[rangeKey];
                    var pts   = range.points.slice().sort(function (a, b) { return a.key - b.key; });
                    if (!pts.length) return;

                    var price = (window.PModInterpolation && window.PModInterpolation.linearInterp)
                        ? window.PModInterpolation.linearInterp(pts, volume)
                        : null;
                    if (price !== null) {
                        // Применяем правила округления Bitrix, если заданы для группы
                        var gidRules = roundingRules && roundingRules[gid];
                        if (gidRules) {
                            price = applyBitrixRounding(price, gidRules);
                        }
                        ranges.push({ from: range.from, to: range.to, price: price });
                    }
                });

                if (ranges.length) {
                    result[gid] = ranges;
                }
            });

            return result;
        },

        updatePriceDisplay: function (container, state, uiRevision) {
            var activeRevision = uiRevision || state._uiRevision;
            if (!PModificator.isRevisionActual(state, activeRevision)) return;
            if (!state.customMode) {
                PModificator.hideCustomPrice(container);
                return;
            }

            var v = state.customVolume;

            // Только тираж (FORMAT пока не используется, согласно ТЗ)
            if (!v) {
                PModificator.hideCustomPrice(container);
                return;
            }

            // Фильтруем предложения по активным «прочим» свойствам
            var filteredOffers = PModificator.filterOffersByProps(state.offers, state.activeOtherProps);

            // Интерполируем все группы × диапазоны (клиентский оптимистичный расчёт)
            var interpolated = PModificator.interpolateAllPrices(filteredOffers, v, state.roundingRules);

            console.log('[pmod price]', {
                productId:         state.productId,
                volume:            v,
                activeOtherProps:  state.activeOtherProps,
                filteredOffersLen: filteredOffers.length,
                interpolated:      interpolated,
            });

            if (!Object.keys(interpolated).length) {
                PModificator.hideCustomPrice(container);
                return;
            }

            // Сохраняем результат в state (для корзины и повторного применения)
            state.lastInterpolatedPrices = interpolated;

            // Определяем «главную» цену (базовая группа → первый диапазон)
            var mainPrice = PModificator.getMainPrice(
                interpolated,
                state.catalogGroups,
                state.canBuyGroups,
                state.mainPriceGroupId
            );
            if (mainPrice !== null) {
                state.lastCalculatedPrice = mainPrice;
            }

            // Перед custom-пересчётом уточняем активную группу цены из DOM Аспро.
            var activeGid = PModificator.detectActivePriceGroupIdFromDom(state);
            if (activeGid) {
                state.mainPriceGroupId = activeGid;
            }

            // Если AJAX-URL настроен — уточняем цену на сервере
            var cfg = window.pmodConfig;
            if (cfg && cfg.ajaxUrl) {
                // Для произвольного тиража работаем по схеме server-first:
                // показываем лоадер и применяем только финальную серверную цену.
                var serverFirst = state.customVolume !== null;
                if (serverFirst) {
                    var visiblePopup = PModificator.getVisiblePopupPriceElement(state);
                    if (visiblePopup) {
                        visiblePopup.classList.add('pmod-price-loading');
                    }
                } else {
                    // Для прочих сценариев оставляем оптимистичный UI.
                    PModificator.showCustomPrice(container, interpolated, state, activeRevision);
                }

                PModificator.fetchServerPrice(state, activeRevision, function (data) {
                    if (!PModificator.isRevisionActual(state, activeRevision)) return;
                    if (!state.customMode) return;
                    if (!data || !data.success) {
                        // Fallback при ошибке сервера: показываем клиентский расчёт и снимаем лоадер.
                        PModificator.showCustomPrice(container, interpolated, state, activeRevision);
                        return;
                    }

                    // Преобразуем ответ сервера в формат, понятный applyPricesToDom
                    var serverInterpolated = {};
                    if (data.ranges && typeof data.ranges === 'object') {
                        Object.keys(data.ranges).forEach(function (gid) {
                            var rows = Array.isArray(data.ranges[gid]) ? data.ranges[gid] : [];
                            var normalized = rows.map(function (row) {
                                return {
                                    from: row && row.from !== undefined ? row.from : null,
                                    to: row && row.to !== undefined ? row.to : null,
                                    price: row && row.price !== undefined ? row.price : null,
                                };
                            }).filter(function (row) {
                                return row.price !== null && !isNaN(Number(row.price));
                            });
                            if (normalized.length) {
                                serverInterpolated[gid] = normalized;
                            }
                        });
                    }
                    if (!Object.keys(serverInterpolated).length && data.prices) {
                        Object.keys(data.prices).forEach(function (gid) {
                            var p = data.prices[gid];
                            serverInterpolated[gid] = [{ from: null, to: null, price: p.raw }];
                        });
                    }

                    if (!Object.keys(serverInterpolated).length) return;

                    state.lastInterpolatedPrices = serverInterpolated;

                    // Если группа ещё не зафиксирована, пытаемся определить её по текущей
                    // "главной" цене, которую в этот момент показывает Aspro.
                    if (!state.mainPriceGroupId) {
                        var basketQty = PModificator.getBasketQuantity(state.productId);
                        var displayedMain = PModificator.getDisplayedMainPriceValue(state);
                        var inferredGroupId = PModificator.inferGroupIdByDisplayedPrice(
                            serverInterpolated,
                            basketQty,
                            displayedMain
                        );
                        if (inferredGroupId) {
                            state.mainPriceGroupId = String(inferredGroupId);
                        }
                    }

                    if (data.mainPrice) {
                        state.lastCalculatedPrice = data.mainPrice.raw;
                        state.mainPriceGroupId = String(data.mainPrice.groupId);
                    } else {
                        var srvMain = PModificator.getMainPrice(
                            serverInterpolated,
                            state.catalogGroups,
                            state.canBuyGroups,
                            state.mainPriceGroupId
                        );
                        // Если сервер не вернул mainPrice (legacy/старый backend),
                        // выбираем минимальную цену по текущему диапазону из server-ranges.
                        if (srvMain === null) {
                            srvMain = PModificator.getMinPriceFromRanges(
                                serverInterpolated,
                                PModificator.getBasketQuantity(state.productId)
                            );
                        }
                        if (srvMain !== null) {
                            state.lastCalculatedPrice = srvMain;
                        }
                    }

                    // Применяем серверные цены к DOM
                    PModificator.applyServerPricesToDom(container, serverInterpolated, state, activeRevision);
                });
                return;
            }

            // Если серверного уточнения нет — показываем клиентский расчёт.
            PModificator.showCustomPrice(container, interpolated, state, activeRevision);
        },

        getVisiblePopupPriceElement: function (state) {
            function isVisible(el) {
                return !!el && (el.offsetParent !== null || el.offsetHeight > 0);
            }

            function collectVisible(root) {
                if (!root || !root.querySelectorAll) return [];
                return Array.prototype.slice.call(root.querySelectorAll('.js-popup-price')).filter(isVisible);
            }

            function chooseNearest(candidates, anchorEl) {
                if (!candidates || !candidates.length) return null;
                if (!anchorEl || !anchorEl.getBoundingClientRect) {
                    return candidates[candidates.length - 1];
                }

                var anchorRect = anchorEl.getBoundingClientRect();
                var anchorCx = anchorRect.left + (anchorRect.width / 2);
                var anchorCy = anchorRect.top + (anchorRect.height / 2);
                var best = null;
                var bestDist = Number.MAX_VALUE;

                candidates.forEach(function (candidate) {
                    var rect = candidate.getBoundingClientRect();
                    var cx = rect.left + (rect.width / 2);
                    var cy = rect.top + (rect.height / 2);
                    var dist = Math.pow(cx - anchorCx, 2) + Math.pow(cy - anchorCy, 2);
                    if (dist < bestDist) {
                        bestDist = dist;
                        best = candidate;
                    }
                });

                return best || candidates[candidates.length - 1];
            }

            var containerEl = state && state.containerEl ? state.containerEl : null;
            if (containerEl) {
                // Локальный scope карточки товара: сначала внутри контейнера SKU.
                var localCandidates = collectVisible(containerEl);
                if (!localCandidates.length) {
                    // Если popup вынесен рядом с SKU — ищем в ближайшем блоке карточки.
                    var cardRoot = containerEl.closest('.catalog-detail__main') || containerEl.parentElement;
                    localCandidates = collectVisible(cardRoot);
                }
                if (localCandidates.length) {
                    // Критерий выбора одного элемента: ближайший к контейнеру SKU.
                    return chooseNearest(localCandidates, containerEl);
                }
            }

            // Глобальный обход оставляем как fallback.
            var globalCandidates = collectVisible(document);
            return chooseNearest(globalCandidates, containerEl);
        },

        getDisplayedMainPriceValue: function (state) {
            var popupPrice = PModificator.getVisiblePopupPriceElement(state);
            if (!popupPrice) return null;

            var valEl = null;
            popupPrice.querySelectorAll('.price__new-val').forEach(function (el) {
                if (!el.closest('template') && valEl === null) {
                    valEl = el;
                }
            });
            if (!valEl) return null;

            var raw = (valEl.textContent || '').replace(/\s+/g, ' ').trim();
            var match = raw.match(/([\d\s]+)(?:[.,](\d+))?/);
            if (!match) return null;

            var intPart = (match[1] || '').replace(/\s/g, '');
            var fracPart = match[2] || '';
            var normalized = intPart + (fracPart ? '.' + fracPart : '');
            var num = Number(normalized);
            return isNaN(num) ? null : num;
        },

        inferGroupIdByDisplayedPrice: function (interpolated, basketQty, displayedPrice) {
            if (displayedPrice === null || displayedPrice === undefined) return null;
            basketQty = basketQty && basketQty > 0 ? basketQty : 1;

            var bestGid = null;
            var bestDiff = Number.MAX_VALUE;

            Object.keys(interpolated || {}).forEach(function (gid) {
                var ranges = interpolated[gid];
                if (!ranges || !ranges.length) return;
                var idx = PModificator.getRangeIndexForQuantity(ranges, basketQty);
                var row = ranges[idx] || ranges[0];
                if (!row || row.price === null || row.price === undefined) return;
                var p = Number(row.price);
                if (isNaN(p)) return;
                var diff = Math.abs(p - displayedPrice);
                if (diff < bestDiff) {
                    bestDiff = diff;
                    bestGid = gid;
                }
            });

            // Допускаем небольшое расхождение из‑за округления.
            return bestDiff <= 1 ? bestGid : null;
        },

        getMinPriceFromRanges: function (interpolated, basketQty) {
            basketQty = basketQty && basketQty > 0 ? basketQty : 1;
            var best = null;
            Object.keys(interpolated || {}).forEach(function (gid) {
                var ranges = interpolated[gid];
                if (!ranges || !ranges.length) return;
                var idx = PModificator.getRangeIndexForQuantity(ranges, basketQty);
                var row = ranges[idx] || ranges[0];
                if (!row || row.price === null || row.price === undefined) return;
                var price = Number(row.price);
                if (isNaN(price)) return;
                if (best === null || price < best) {
                    best = price;
                }
            });
            return best;
        },

        getMainPrice: function (interpolated, catalogGroups, canBuyGroups, preferredGroupId) {
            var desc = PModificator.getMainPriceDescriptor(
                interpolated,
                catalogGroups,
                canBuyGroups,
                preferredGroupId,
                1,
                null
            );
            return desc ? desc.price : null;
        },

        getRangeIndexForQuantity: function (ranges, basketQty) {
            if (!ranges || !ranges.length) return 0;
            for (var i = 0; i < ranges.length; i++) {
                var r = ranges[i] || {};
                var from = r.from !== null && r.from !== undefined ? Number(r.from) : null;
                var to   = r.to   !== null && r.to   !== undefined ? Number(r.to)   : null;
                var okFrom = (from === null) || (basketQty >= from);
                var okTo   = (to === null) || (basketQty <= to);
                if (okFrom && okTo) return i;
            }
            return 0;
        },

        getMainPriceDescriptor: function (interpolated, catalogGroups, canBuyGroups, preferredGroupId, basketQty, allowedGroupIds) {
            basketQty = basketQty && basketQty > 0 ? basketQty : 1;
            var canBuyLookup = {};
            (canBuyGroups || []).forEach(function (gid) {
                canBuyLookup[String(gid)] = true;
            });
            var allowedLookup = null;
            if (allowedGroupIds && allowedGroupIds.length) {
                allowedLookup = {};
                allowedGroupIds.forEach(function (gid) { allowedLookup[String(gid)] = true; });
            }

            if (
                preferredGroupId &&
                interpolated[preferredGroupId] &&
                interpolated[preferredGroupId].length &&
                (!allowedLookup || allowedLookup[String(preferredGroupId)])
            ) {
                var prefRanges = interpolated[preferredGroupId];
                var prefIdx = PModificator.getRangeIndexForQuantity(prefRanges, basketQty);
                var prefRow = prefRanges[prefIdx] || prefRanges[0];
                if (prefRow && prefRow.price !== null && prefRow.price !== undefined) {
                    return {
                        gid: String(preferredGroupId),
                        rangeIndex: prefIdx,
                        price: Number(prefRow.price),
                    };
                }
            }

            // Приоритет: порядок групп, видимых в popup-таблице (как у Aspro).
            if (allowedGroupIds && allowedGroupIds.length) {
                for (var ai = 0; ai < allowedGroupIds.length; ai++) {
                    var allowedGid = String(allowedGroupIds[ai]);
                    var allowedRanges = interpolated[allowedGid];
                    if (!allowedRanges || !allowedRanges.length) continue;
                    if (!canBuyLookup[allowedGid]) continue;
                    var allowedIdx = PModificator.getRangeIndexForQuantity(allowedRanges, basketQty);
                    var allowedRow = allowedRanges[allowedIdx] || allowedRanges[0];
                    if (allowedRow && allowedRow.price !== null && allowedRow.price !== undefined) {
                        return {
                            gid: allowedGid,
                            rangeIndex: allowedIdx,
                            price: Number(allowedRow.price),
                        };
                    }
                }
                // Если из visible-групп нет buyable — берём первую visible с ценой
                for (var aj = 0; aj < allowedGroupIds.length; aj++) {
                    var anyGid = String(allowedGroupIds[aj]);
                    var anyRanges = interpolated[anyGid];
                    if (!anyRanges || !anyRanges.length) continue;
                    var anyIdx = PModificator.getRangeIndexForQuantity(anyRanges, basketQty);
                    var anyRow = anyRanges[anyIdx] || anyRanges[0];
                    if (anyRow && anyRow.price !== null && anyRow.price !== undefined) {
                        return {
                            gid: anyGid,
                            rangeIndex: anyIdx,
                            price: Number(anyRow.price),
                        };
                    }
                }
            }

            var candidates = [];
            Object.keys(interpolated).forEach(function (gid) {
                if (allowedLookup && !allowedLookup[String(gid)]) return;
                var ranges = interpolated[gid];
                if (!ranges || !ranges.length) return;
                var idx = PModificator.getRangeIndexForQuantity(ranges, basketQty);
                var row = ranges[idx] || ranges[0];
                if (row.price === null || row.price === undefined || isNaN(Number(row.price))) return;
                var g = catalogGroups && catalogGroups[gid];
                candidates.push({
                    gid: gid,
                    rangeIndex: idx,
                    price: Number(row.price),
                    canBuy: !!canBuyLookup[String(gid)],
                    base: !!(g && g.base),
                    order: allowedGroupIds && allowedGroupIds.length ? allowedGroupIds.indexOf(String(gid)) : -1,
                });
            });

            if (!candidates.length) return null;

            var buyable = candidates.filter(function (c) { return c.canBuy; });
            var pool = buyable.length ? buyable : candidates;

            pool.sort(function (a, b) {
                if (a.price === b.price) {
                    var aOrd = a.order >= 0 ? a.order : Number.MAX_SAFE_INTEGER;
                    var bOrd = b.order >= 0 ? b.order : Number.MAX_SAFE_INTEGER;
                    if (aOrd !== bOrd) return aOrd - bOrd;
                    if (a.base !== b.base) return a.base ? -1 : 1;
                    return parseInt(a.gid, 10) - parseInt(b.gid, 10);
                }
                return a.price - b.price;
            });

            return pool[0];
        },

        showCustomPrice: function (container, interpolated, state, uiRevision) {
            if (!PModificator.isRevisionActual(state, uiRevision)) return;
            var popupPrice = PModificator.getVisiblePopupPriceElement(state);
            var cartEl     = document.querySelector('.catalog-detail__cart');

            // Делаем кнопку корзины видимой (X-ТП с 0.01 может иметь её)
            if (cartEl) {
                if (cartEl._pmodWasHidden === undefined) {
                    cartEl._pmodWasHidden = cartEl.classList.contains('hidden');
                }
                cartEl.classList.remove('hidden');
            }

            if (!popupPrice) {
                // Запасной вариант: собственный элемент модуля
                var mainPrice = PModificator.getMainPrice(
                    interpolated,
                    state.catalogGroups,
                    state.canBuyGroups,
                    state.mainPriceGroupId
                );
                if (mainPrice === null) return;
                var priceEl = container.querySelector('.pmod-custom-price');
                if (!priceEl) {
                    priceEl = document.createElement('div');
                    priceEl.className = 'pmod-custom-price';
                    var buyBtn = document.querySelector('.detail-buy, .js-basket-btn, [data-entity="basket-button"]');
                    var insertTarget = buyBtn ? buyBtn.parentNode : container.parentNode;
                    if (insertTarget) insertTarget.insertBefore(priceEl, buyBtn);
                }
                priceEl.innerHTML =
                    '<span class="pmod-custom-price__label">Расчётная цена:</span> ' +
                    '<span class="pmod-custom-price__value">' + formatPrice(mainPrice) + '</span>' +
                    '<span class="pmod-custom-price__note"> (предварительный расчёт)</span>';
                priceEl.style.display = '';
                return;
            }

            // Добавляем класс-заглушку (скрывает цену, показывает анимацию)
            popupPrice.classList.add('pmod-price-loading');

            // Отменяем предыдущий ожидающий апдейт
            PModificator.cancelPendingPriceUpdate(popupPrice);

            // Функция применения цен
            var applied = false;
            var applyPrices = function () {
                if (applied) return;
                applied = true;
                PModificator.cancelPendingPriceUpdate(popupPrice);
                if (!PModificator.isRevisionActual(state, uiRevision)) return;

                if (!state.customMode) {
                    popupPrice.classList.remove('pmod-price-loading');
                    return;
                }

                popupPrice._pmodUpdating = true;
                PModificator.applyPricesToDom(popupPrice, interpolated, state);
                popupPrice._pmodUpdating = false;

                popupPrice.classList.remove('pmod-price-loading');
            };

            // MutationObserver: срабатывает когда Аспро обновит DOM через AJAX
            var observer = new MutationObserver(function () {
                if (popupPrice._pmodUpdating) return;
                if (!PModificator.isRevisionActual(state, uiRevision)) return;
                observer.disconnect();
                applyPrices();
            });
            observer.observe(popupPrice, { childList: true, subtree: true, characterData: true });
            popupPrice._pmodObserver = observer;

            // Fallback-таймаут: если Аспро не обновит DOM (X-ТП уже активен),
            // применяем цены через PRICE_UPDATE_TIMEOUT_MS
            popupPrice._pmodFallbackTimer = setTimeout(function () {
                if (!PModificator.isRevisionActual(state, uiRevision)) return;
                if (popupPrice._pmodObserver) {
                    popupPrice._pmodObserver.disconnect();
                    delete popupPrice._pmodObserver;
                }
                applyPrices();
            }, PRICE_UPDATE_TIMEOUT_MS);
        },

        cancelPendingPriceUpdate: function (popupPrice) {
            if (!popupPrice) return;
            if (popupPrice._pmodObserver) {
                popupPrice._pmodObserver.disconnect();
                delete popupPrice._pmodObserver;
            }
            if (popupPrice._pmodFallbackTimer) {
                clearTimeout(popupPrice._pmodFallbackTimer);
                delete popupPrice._pmodFallbackTimer;
            }
        },

        applyPricesToDom: function (popupPrice, interpolated, state) {
            // ── 1. Определяем порядок групп ──────────────────────────────────
            var priceCodeOrder = null;
            try {
                var priceConfigAttr = popupPrice.getAttribute('data-price-config');
                if (priceConfigAttr) {
                    var cfg = JSON.parse(priceConfigAttr);
                    priceCodeOrder = (cfg && cfg.PRICE_CODE) || null;
                }
            } catch (e) {}

            // Сопоставление name → gid из catalogGroups
            var nameToGid = {};
            Object.keys(state.catalogGroups || {}).forEach(function (gid) {
                var g = state.catalogGroups[gid];
                if (g && g.name) nameToGid[g.name] = gid;
            });

            // Упорядоченный список group IDs
            var orderedGids;
            if (priceCodeOrder && priceCodeOrder.length) {
                orderedGids = [];
                priceCodeOrder.forEach(function (name) {
                    var gid = nameToGid[name];
                    if (gid) orderedGids.push(gid);
                });
            }
            if (!orderedGids || !orderedGids.length) {
                // Fallback: числовой порядок ключей interpolated
                orderedGids = Object.keys(interpolated).sort(function (a, b) {
                    return parseInt(a, 10) - parseInt(b, 10);
                });
            }

            // ── 2. Плоский список цен ─────────────────────────────────────────
            var flatPrices = [];
            orderedGids.forEach(function (gid) {
                var ranges = interpolated[gid];
                if (!ranges) return;
                ranges.forEach(function (range) {
                    flatPrices.push(range.price);
                });
            });

            if (!flatPrices.length) return;

            // ── 3. Обновляем цены в popup-таблице ────────────────────────────
            // Popup-контент находится внутри template.xpopover-template.
            // Нативный <template> хранит содержимое в .content (DocumentFragment),
            // поэтому обычный querySelectorAll не проникает внутрь.
            var templateEl = popupPrice.querySelector('template.xpopover-template');
            var popupValEls = [];
            if (templateEl) {
                var root = (templateEl.content && templateEl.content.querySelectorAll)
                    ? templateEl.content
                    : templateEl;
                popupValEls = Array.prototype.slice.call(root.querySelectorAll('.price__new-val'));
            }

            // ── 4. Позиционная замена цен ─────────────────────────────────────
            var DEFAULT_UNIT_SPAN = '<span>тираж</span>';

            function replacePriceInEl(el, price) {
                var isInt = price % 1 === 0;
                var priceStr = price.toLocaleString('ru-RU', {
                    minimumFractionDigits: isInt ? 0 : 2,
                    maximumFractionDigits: isInt ? 0 : 2,
                });
                var unitSpan = el.querySelector('span');
                var unitText = unitSpan ? unitSpan.outerHTML : DEFAULT_UNIT_SPAN;
                el.innerHTML = priceStr + '\u00a0₽/' + unitText;
            }

            popupValEls.forEach(function (el, idx) {
                if (idx < flatPrices.length) replacePriceInEl(el, flatPrices[idx]);
            });

            // ── 5. Обновляем главную видимую цену (снаружи template) ──────────
            var basketQty = PModificator.getBasketQuantity(state.productId);
            var mainDesc = PModificator.getMainPriceDescriptor(
                interpolated,
                state.catalogGroups,
                state.canBuyGroups,
                state.mainPriceGroupId,
                basketQty,
                orderedGids
            );
            var mainPrice = mainDesc ? mainDesc.price : null;
            var mainPriceUpdated = false;
            if (mainPrice !== null) {
                var allPriceVals = popupPrice.querySelectorAll('.price__new-val');
                allPriceVals.forEach(function (el) {
                    if (!el.closest('template')) {
                        replacePriceInEl(el, mainPrice);
                        mainPriceUpdated = true;
                    }
                });
            }

            // Если ни popup-цены, ни главная цена не были обновлены — fallback: перестраиваем блок
            if (!popupValEls.length && !mainPriceUpdated && mainPrice !== null) {
                var isInt = mainPrice % 1 === 0;
                var priceStr = mainPrice.toLocaleString('ru-RU', {
                    minimumFractionDigits: isInt ? 0 : 2,
                    maximumFractionDigits: isInt ? 0 : 2,
                });
                popupPrice.innerHTML =
                    '<div class="prices">' +
                      '<div class="price color_dark price--current">' +
                        '<div class="price__row">' +
                          '<div class="price__new fw-500">' +
                            '<span class="price__new-val font_24">' +
                              priceStr + '\u00a0₽/' + DEFAULT_UNIT_SPAN +
                            '</span>' +
                          '</div>' +
                        '</div>' +
                      '</div>' +
                    '</div>';
            }

            // Подсветка текущей строки цены в popup-таблице (price--current)
            if (templateEl && templateEl.content && mainDesc) {
                var priceRows = Array.prototype.slice.call(templateEl.content.querySelectorAll('.price'));
                priceRows.forEach(function (row) { row.classList.remove('price--current'); });

                var flatMeta = [];
                orderedGids.forEach(function (gid) {
                    var ranges = interpolated[gid];
                    if (!ranges) return;
                    ranges.forEach(function (_range, idx) {
                        flatMeta.push({ gid: String(gid), rangeIndex: idx });
                    });
                });

                for (var i = 0; i < flatMeta.length && i < priceRows.length; i++) {
                    if (
                        flatMeta[i].gid === String(mainDesc.gid) &&
                        flatMeta[i].rangeIndex === mainDesc.rangeIndex
                    ) {
                        priceRows[i].classList.add('price--current');
                        break;
                    }
                }
            }
        },

        hideCustomPrice: function (container) {
            var stateList = window._pmodActiveStates || [];
            stateList.forEach(function (st) {
                if (st && st._otherPropRecalcTimer) {
                    clearTimeout(st._otherPropRecalcTimer);
                    st._otherPropRecalcTimer = null;
                }
            });
            // Очищаем все .js-popup-price (скрытые и видимые)
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                PModificator.cancelPendingPriceUpdate(el);
                el.classList.remove('pmod-price-loading');
                delete el._pmodUpdating;
            });
            var cartEl     = document.querySelector('.catalog-detail__cart');

            var legacyEl = document.querySelector('.pmod-custom-price');
            if (legacyEl) legacyEl.style.display = 'none';

            if (cartEl && cartEl._pmodWasHidden) {
                cartEl.classList.add('hidden');
                delete cartEl._pmodWasHidden;
            }
        }

    };
})();
