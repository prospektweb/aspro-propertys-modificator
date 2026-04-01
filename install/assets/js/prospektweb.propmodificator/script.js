/**
 * prospektweb.propmodificator — главный фронтенд-скрипт
 *
 * Добавляет UI для ввода произвольного формата (Ш×В) и тиража
 * на карточке товара Аспро: Премьер.
 *
 * Требует: конфигурационный объект window.pmodConfig (инжектируется PHP)
 */

;(function () {
    'use strict';

    // ── Константы ─────────────────────────────────────────────────────────────

    var DEBOUNCE_MS              = 300;
    var PRICE_UPDATE_TIMEOUT_MS  = 400; // Fallback таймаут ожидания AJAX-обновления Аспро

    // ── Утилиты ───────────────────────────────────────────────────────────────

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    function clamp(val, min, max) {
        return Math.max(min, Math.min(max, val));
    }

    /**
     * Синхронизирует query-параметр pmod_volume с текущим URL через history.replaceState.
     * Если volume задан — добавляет/обновляет параметр; если null/0 — удаляет его.
     * Остальные параметры (oid и др.) остаются нетронутыми.
     */
    function syncUrlPmodVolume(volume) {
        if (!window.history || !window.history.replaceState) return;
        var url    = new URL(window.location.href);
        var params = url.searchParams;
        if (volume != null && volume !== 0 && volume !== '') {
            params.set('pmod_volume', volume);
        } else {
            params.delete('pmod_volume');
        }
        window.history.replaceState(null, '', url.toString());
    }

    function formatPrice(price) {
        var isInt = price % 1 === 0;
        return price.toLocaleString('ru-RU', {
            minimumFractionDigits: isInt ? 0 : 2,
            maximumFractionDigits: isInt ? 0 : 2,
        }) + ' ₽';
    }

    /**
     * Применяет правила округления Bitrix к цене.
     * Выбирается правило с наибольшим порогом price, не превышающим текущую цену.
     *
     * @param {number} price
     * @param {Array<{price: number, type: number, precision: number}>} rules
     * @returns {number}
     */
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

    // ── Интерполяция цен (клиентская сторона, только для отображения) ─────────

    /**
     * Линейная интерполяция.
     * @param {Array<{key: number, price: number}>} points — отсортированный массив
     * @param {number} value
     * @returns {number|null}
     */
    function linearInterp(points, value) {
        if (!points.length) return null;

        if (value <= points[0].key) return points[0].price;
        if (value >= points[points.length - 1].key) return points[points.length - 1].price;

        for (var i = 0; i < points.length - 1; i++) {
            var lo = points[i];
            var hi = points[i + 1];

            if (value >= lo.key && value <= hi.key) {
                var t = (value - lo.key) / (hi.key - lo.key);
                return lo.price + t * (hi.price - lo.price);
            }
        }

        return points[points.length - 1].price;
    }

    /**
     * Найти нижнего и верхнего соседа в отсортированном массиве чисел.
     * @returns {[number, number]}
     */
    function findNeighbors(sorted, value) {
        var low  = sorted[0];
        var high = sorted[sorted.length - 1];

        for (var i = 0; i < sorted.length; i++) {
            if (sorted[i] <= value) low  = sorted[i];
            if (sorted[i] >= value) { high = sorted[i]; break; }
        }

        return [low, high];
    }

    /**
     * Билинейная интерполяция цены по площади (ширина × высота) и тиражу.
     *
     * @param {Array<{width, height, volume, price}>} offers
     * @param {number} width
     * @param {number} height
     * @param {number} volume
     * @returns {number|null}
     */
    function bilinearInterp(offers, width, height, volume) {
        var area = width * height;

        // Только ТП с заполненными данными
        var pts = offers.filter(function (o) {
            return o.width && o.height && o.volume && o.price;
        }).map(function (o) {
            return { area: o.width * o.height, volume: o.volume, price: o.price };
        });

        if (!pts.length) return null;

        // Дедупликация значений площади и тиража: Set гарантирует уникальность,
        // spread-оператор конвертирует обратно в массив для сортировки.
        var areas   = [...new Set(pts.map(function (p) { return p.area; }))].sort(function (a, b) { return a - b; });
        var volumes = [...new Set(pts.map(function (p) { return p.volume; }))].sort(function (a, b) { return a - b; });

        // Если уникальных значений недостаточно для билинейной интерполяции — деградируем
        if (areas.length < 2 || volumes.length < 2) {
            // Деградируем до линейной интерполяции
            var areaPoints = areas.map(function (a) {
                var match = pts.find(function (p) { return p.area === a; });
                return { key: a, price: match ? match.price : 0 };
            });
            return linearInterp(areaPoints, area);
        }

        var areaNeighbors   = findNeighbors(areas, area);
        var volumeNeighbors = findNeighbors(volumes, volume);

        var aLow  = areaNeighbors[0];   var aHigh  = areaNeighbors[1];
        var vLow  = volumeNeighbors[0]; var vHigh  = volumeNeighbors[1];

        function findPrice(a, v) {
            var closest = null;
            var bestDist = Infinity;
            pts.forEach(function (p) {
                var d = Math.abs(p.area - a) + Math.abs(p.volume - v);
                if (d < bestDist) { bestDist = d; closest = p.price; }
            });
            return closest;
        }

        var q11 = findPrice(aLow,  vLow);
        var q12 = findPrice(aLow,  vHigh);
        var q21 = findPrice(aHigh, vLow);
        var q22 = findPrice(aHigh, vHigh);

        if (q11 === null || q12 === null || q21 === null || q22 === null) return null;

        // Интерполяция по площади, затем по тиражу
        var tA = aLow === aHigh ? 0 : (area - aLow) / (aHigh - aLow);
        var r1 = q11 + tA * (q21 - q11);
        var r2 = q12 + tA * (q22 - q12);

        var tV = vLow === vHigh ? 0 : (volume - vLow) / (vHigh - vLow);
        return r1 + tV * (r2 - r1);
    }

    // ── Основной модуль ───────────────────────────────────────────────────────

    var PModificator = {

        /**
         * Инициализация.
         * Вызывается после DOMContentLoaded.
         * Конфигурация читается из window.pmodConfig (инжектируется PHP).
         */
        init: function () {
            var cfg = window.pmodConfig;
            if (!cfg) {
                console.warn('[pmod] window.pmodConfig не определён — модуль не инициализирован');
                return;
            }

            var containers = document.querySelectorAll('.sku-props');
            if (!containers.length) return;

            // Добавляем класс обрезки к h1 на детальной странице товара
            var h1 = document.querySelector('h1');
            if (h1) {
                h1.classList.add('pmod-title-clamp');
            }

            // Синхронизируем title с textContent при любых изменениях h1
            PModificator.setupH1TitleSync();

            containers.forEach(function (container) {
                PModificator.initContainer(container, cfg);
            });

            // После обновления SKU в Аспро повторно применяем кастомную цену,
            // чтобы "техническая" цена X-ТП не перетирала расчёт pmod.
            PModificator.hookAsproSkuFinalAction();
        },

        /**
         * Инициализация одного контейнера .sku-props
         * @param {Element} container
         * @param {Object}  cfg  window.pmodConfig
         */
        initContainer: function (container, cfg) {
            var productId = parseInt(container.dataset.itemId, 10);
            if (!productId) return;

            var productCfg = cfg.products && cfg.products[productId];
            if (!productCfg) return;

            var formatPropId = productCfg.formatPropId;
            var volumePropId = productCfg.volumePropId;
            var allPropIds   = productCfg.allPropIds || [];
            var catalogGroups = productCfg.catalogGroups || {};

            // Считываем текущий активный выбор "прочих" свойств из DOM
            var initialOtherProps = {};
            allPropIds.forEach(function (pid) {
                var innerEl = container.querySelector('.sku-props__inner[data-id="' + pid + '"]');
                if (!innerEl) return;
                var activeBtn = innerEl.querySelector('.sku-props__value--active');
                if (activeBtn && activeBtn.dataset.onevalue) {
                    initialOtherProps[pid] = parseInt(activeBtn.dataset.onevalue, 10);
                }
            });

            var state = {
                productId:        productId,
                offers:           productCfg.offers || [],
                formatCfg:        productCfg.formatSettings || {},
                volumeCfg:        productCfg.volumeSettings || {},
                volumeEnumMap:    productCfg.volumeEnumMap || {},
                formatEnumMap:    productCfg.formatEnumMap || {},
                catalogGroups:    catalogGroups,
                canBuyGroups:     productCfg.canBuyGroups || [],
                allPropIds:       allPropIds,
                roundingRules:    productCfg.roundingRules || {},
                activeOtherProps: initialOtherProps,
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

            // Перехватываем отправку корзины
            PModificator.hookBasket(container, state);
        },

        // ── События Аспро ────────────────────────────────────────────────────

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
                    if (!state || !state.customMode || !state.containerEl) return;

                    // Если wrapper известен — применяем только к затронутому контейнеру
                    if (wrapperEl && !wrapperEl.contains(state.containerEl) && wrapperEl !== state.containerEl) {
                        return;
                    }

                    // Один отложенный re-apply после асинхронного апдейта Аспро
                    // заметно снижает визуальное "моргание" цены.
                    PModificator.scheduleReapplyCustomPrice(state.containerEl, state);
                });
            });
        },

        // ── Синхронизация h1 title с textContent ─────────────────────────────

        /**
         * Устанавливает MutationObserver на h1, чтобы атрибут title всегда
         * совпадал с текстовым содержимым (Aspro обновляет textContent, но не title).
         */
        setupH1TitleSync: function () {
            var h1 = document.querySelector('h1.pmod-title-clamp');
            if (!h1) h1 = document.querySelector('h1');
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

        /**
         * Заменяет последний сегмент в h1 (после « | ») на volumeStr
         * и синхронно обновляет и textContent, и title.
         *
         * @param {string} volumeStr  — например «4 950 экз»
         */
        updateH1WithVolume: function (volumeStr) {
            var h1 = document.querySelector('h1.pmod-title-clamp');
            if (!h1) h1 = document.querySelector('h1');
            if (!h1) return;

            var text  = h1.textContent.trim();
            var parts = text.split(' | ');
            if (parts.length < 2) return;

            parts[parts.length - 1] = volumeStr;
            var newText = parts.join(' | ');

            h1._pmodUpdatingTitle = true;
            h1.textContent = newText;
            h1.title       = newText;
            h1._pmodUpdatingTitle = false;
        },

        /**
         * Пересчитывает customMode по фактическим кастомным значениям.
         * Кастомный режим активен, если задан произвольный тираж
         * или одновременно заданы произвольные ширина и высота.
         */
        recomputeCustomMode: function (state) {
            state.customMode = !!(
                state.customVolume !== null ||
                (state.customWidth !== null && state.customHeight !== null)
            );
        },

        // ── Улучшение свойства ФОРМАТ ─────────────────────────────────────────

        enhanceFormatProp: function (inner, state, container) {
            var valuesEl  = inner.querySelector('.sku-props__values');
            if (!valuesEl) return;

            var fmtCfg = state.formatCfg;
            var minW   = fmtCfg.MIN_WIDTH  || 10;
            var maxW   = fmtCfg.MAX_WIDTH  || 1200;
            var minH   = fmtCfg.MIN_HEIGHT || 10;
            var maxH   = fmtCfg.MAX_HEIGHT || 1200;
            var step   = fmtCfg.STEP       || 1;

            // Начальное значение: активная кнопка или первая
            var activeBtn = valuesEl.querySelector('.sku-props__value--active') ||
                            valuesEl.querySelector('.sku-props__value');
            var initW = minW, initH = minH;

            if (activeBtn) {
                // Предпочитаем VALUE_XML_ID из formatEnumMap (вида "210x297"), fallback — data-title
                var afmtEnumId = activeBtn.dataset.onevalue || '';
                var afmtXmlId  = (state.formatEnumMap && afmtEnumId && state.formatEnumMap[afmtEnumId] !== undefined)
                    ? state.formatEnumMap[afmtEnumId]
                    : (activeBtn.dataset.title || '');
                var parts = afmtXmlId.toLowerCase().split('x');
                if (parts.length === 2) {
                    initW = parseInt(parts[0], 10) || minW;
                    initH = parseInt(parts[1], 10) || minH;
                }
            }

            var ui = document.createElement('div');
            ui.className = 'pmod-format-ui';
            ui.innerHTML = [
                '<div class="pmod-format-inputs">',
                  '<div class="pmod-input-group">',
                    '<label class="pmod-label">Ширина, мм</label>',
                    '<div class="pmod-counter">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__minus" aria-label="Уменьшить ширину">&#8722;</button>',
                      '<input type="number" class="pmod-counter__input pmod-input-width"',
                             ' min="' + minW + '" max="' + maxW + '" step="' + step + '"',
                             ' value="' + initW + '" autocomplete="off">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__plus" aria-label="Увеличить ширину">+</button>',
                    '</div>',
                  '</div>',
                  '<span class="pmod-format-x">&#215;</span>',
                  '<div class="pmod-input-group">',
                    '<label class="pmod-label">Высота, мм</label>',
                    '<div class="pmod-counter">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__minus" aria-label="Уменьшить высоту">&#8722;</button>',
                      '<input type="number" class="pmod-counter__input pmod-input-height"',
                             ' min="' + minH + '" max="' + maxH + '" step="' + step + '"',
                             ' value="' + initH + '" autocomplete="off">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__plus" aria-label="Увеличить высоту">+</button>',
                    '</div>',
                  '</div>',
                '</div>',
            ].join('');

            // Вставляем UI перед стандартными кнопками
            valuesEl.parentNode.insertBefore(ui, valuesEl);

            var widthInput  = ui.querySelector('.pmod-input-width');
            var heightInput = ui.querySelector('.pmod-input-height');

            // Найти кнопку «Произвольный формат» (XML_ID="X")
            var customBtn = PModificator.findCustomButton(valuesEl, state.formatEnumMap);

            function onFormatChange(isImmediate) {
                var rawW = parseInt(widthInput.value, 10);
                var rawH = parseInt(heightInput.value, 10);
                var w, h;

                if (isImmediate) {
                    // Кнопки +/- или blur: применяем clamp сразу
                    w = clamp(isNaN(rawW) ? minW : rawW, minW, maxW);
                    h = clamp(isNaN(rawH) ? minH : rawH, minH, maxH);
                    widthInput.value  = w;
                    heightInput.value = h;
                } else {
                    // Ручной ввод: не перезаписываем инпут
                    w = clamp(isNaN(rawW) ? minW : rawW, minW, maxW);
                    h = clamp(isNaN(rawH) ? minH : rawH, minH, maxH);
                }

                // Ищем точное совпадение с пресетом
                var matched = PModificator.findFormatPreset(valuesEl, w, h, state.formatEnumMap);
                if (matched) {
                    // Кликаем на пресет — стандартная логика Аспро
                    if (!matched.classList.contains('sku-props__value--active')) {
                        inner._pmodProgrammaticChange = true;
                        matched.click();
                    }
                    state.customWidth  = null;
                    state.customHeight = null;
                    PModificator.recomputeCustomMode(state);
                } else {
                    state.customWidth  = w;
                    state.customHeight = h;
                    PModificator.recomputeCustomMode(state);

                    // Выбираем кнопку «Произвольный формат» или ближайший пресет
                    if (customBtn) {
                        if (!customBtn.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            customBtn.click();
                        }
                    } else {
                        var nearest = PModificator.findNearestFormatPreset(valuesEl, w, h, state.formatEnumMap);
                        if (nearest && !nearest.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            nearest.click();
                        }
                    }
                }

                PModificator.updatePriceDisplay(container, state);
            }

            var debouncedChange = debounce(function () { onFormatChange(false); }, DEBOUNCE_MS);

            widthInput.addEventListener('input',  debouncedChange);
            heightInput.addEventListener('input', debouncedChange);

            // Валидация при потере фокуса
            [widthInput, heightInput].forEach(function (inp) {
                inp.addEventListener('blur', function () {
                    var raw = parseInt(inp.value, 10);
                    var min = parseInt(inp.min, 10);
                    var max = parseInt(inp.max, 10);
                    var s   = parseInt(inp.step, 10) || 1;
                    var v   = clamp(isNaN(raw) ? min : raw, min, max);
                    v = Math.round(v / s) * s;
                    v = clamp(v, min, max);
                    inp.value = v;
                    onFormatChange(true);
                });
            });

            // +/- кнопки
            ui.querySelectorAll('.pmod-counter__minus, .pmod-counter__plus').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var inp     = btn.closest('.pmod-counter').querySelector('.pmod-counter__input');
                    var current = parseInt(inp.value, 10) || parseInt(inp.min, 10);
                    var s       = parseInt(inp.step, 10) || 1;
                    var newVal  = btn.classList.contains('pmod-counter__plus') ? current + s : current - s;
                    inp.value   = clamp(newVal, parseInt(inp.min, 10), parseInt(inp.max, 10));
                    onFormatChange(true);
                });
            });

            // Колесико мыши
            [widthInput, heightInput].forEach(function (inp) {
                inp.addEventListener('wheel', function (e) {
                    if (document.activeElement !== inp) return;
                    e.preventDefault();
                    var current = parseInt(inp.value, 10) || parseInt(inp.min, 10);
                    var s       = parseInt(inp.step, 10) || 1;
                    var newVal  = e.deltaY < 0 ? current + s : current - s;
                    inp.value   = clamp(newVal, parseInt(inp.min, 10), parseInt(inp.max, 10));
                    onFormatChange(true);
                }, { passive: false });
            });

            // Сохраняем ссылки для обновления из обработчика кликов
            inner._pmodWidthInput  = widthInput;
            inner._pmodHeightInput = heightInput;
        },

        // ── Улучшение свойства ТИРАЖ ──────────────────────────────────────────

        enhanceVolumeProp: function (inner, state, container) {
            var valuesEl = inner.querySelector('.sku-props__values');
            if (!valuesEl) return;

            var volCfg        = state.volumeCfg;
            var volumeEnumMap = state.volumeEnumMap; // enumId → xmlIdNumber
            var minV   = volCfg.MIN  || 10;
            var maxV   = volCfg.MAX  || 100000;
            var step   = volCfg.STEP || 1;

            // Скрываем стандартные кнопки пресетов (остаются в DOM)
            valuesEl.classList.add('pmod-preset-buttons--hidden');

            // Собираем пресеты из скрытых кнопок
            var presetBtns = valuesEl.querySelectorAll('.sku-props__value');
            var activeBtn  = valuesEl.querySelector('.sku-props__value--active') ||
                             valuesEl.querySelector('.sku-props__value');

            // Начальное значение через VALUE_XML_ID из enumMap
            var initXmlId = minV;
            if (activeBtn) {
                var ae = activeBtn.dataset.onevalue;
                var aeXmlId = (volumeEnumMap && ae && volumeEnumMap[ae] !== undefined)
                    ? volumeEnumMap[ae]
                    : null;
                initXmlId = (aeXmlId !== null && aeXmlId !== 'X')
                    ? (parseInt(aeXmlId, 10) || minV)
                    : (parseInt(activeBtn.dataset.title, 10) || minV);
            }

            var ui = document.createElement('div');
            ui.className = 'pmod-volume-ui';
            ui.innerHTML = [
                '<div class="pmod-volume-row">',
                  '<div class="pmod-counter">',
                    '<button type="button" class="pmod-counter__btn pmod-counter__minus" aria-label="Уменьшить тираж">&#8722;</button>',
                    '<input type="number"',
                           ' class="pmod-counter__input pmod-input-volume"',
                           ' min="' + minV + '" max="' + maxV + '" step="' + step + '"',
                           ' value="' + initXmlId + '" autocomplete="off"',
                           ' aria-label="Тираж">',
                    '<button type="button" class="pmod-counter__btn pmod-counter__plus" aria-label="Увеличить тираж">+</button>',
                  '</div>',
                '</div>',
            ].join('');

            valuesEl.parentNode.insertBefore(ui, valuesEl);

            var volumeInput = ui.querySelector('.pmod-input-volume');

            // ── Предварительно строим карту xmlId → кнопка для быстрого поиска ──
            var xmlIdToBtnMap = {};
            presetBtns.forEach(function (btn) {
                var eid = btn.dataset.onevalue || '';
                var xmlId = (volumeEnumMap && eid && volumeEnumMap[eid] !== undefined)
                    ? parseInt(volumeEnumMap[eid], 10)
                    : (parseInt(btn.dataset.title, 10) || 0);
                if (xmlId) xmlIdToBtnMap[xmlId] = btn;
            });

            // Найти кнопку «Произвольный тираж» (XML_ID="X")
            var customBtn = PModificator.findCustomButton(valuesEl, volumeEnumMap);

            // Скрываем кнопку «Произвольный тираж» — пользователь управляет тиражом через инпут
            if (customBtn) {
                customBtn.style.display = 'none';
            }

            // Находим span лейбла тиража для обновления при произвольном вводе
            var volumeLabelSpan = inner.querySelector('.sku-props__title .sku-props__js-size');

            // ── Вспомогательная функция: найти кнопку пресета по VALUE_XML_ID ──
            function findBtnByXmlId(xmlId) {
                return xmlIdToBtnMap[xmlId] || null;
            }

            // ── Клик по скрытой кнопке пресета (фоново) ────────────────────────
            function clickPresetByXmlId(xmlId) {
                var btn = findBtnByXmlId(xmlId);
                if (btn && !btn.classList.contains('sku-props__value--active')) {
                    btn.click();
                }
            }

            // ── Показать/скрыть кнопки пресетов при фокусе/потере фокуса ────────
            volumeInput.addEventListener('focus', function () {
                valuesEl.classList.remove('pmod-preset-buttons--hidden');
                valuesEl.classList.add('pmod-preset-buttons--floating');
            });

            volumeInput.addEventListener('blur', function () {
                setTimeout(function () {
                    valuesEl.classList.add('pmod-preset-buttons--hidden');
                    valuesEl.classList.remove('pmod-preset-buttons--floating');
                }, 200);
            });

            // ── Обработчик изменения инпута ────────────────────────────────────
            function onVolumeChange(isImmediate) {
                var raw = parseInt(volumeInput.value, 10);
                var v;

                if (isImmediate) {
                    // Кнопки +/- или blur: применяем clamp сразу
                    v = clamp(isNaN(raw) ? minV : raw, minV, maxV);
                    volumeInput.value = v;
                } else {
                    // Ручной ввод: не перезаписываем инпут
                    v = clamp(isNaN(raw) ? minV : raw, minV, maxV);
                }

                var matchedBtn = findBtnByXmlId(v);
                if (matchedBtn) {
                    if (state._volumeLabelTimer) {
                        clearTimeout(state._volumeLabelTimer);
                        state._volumeLabelTimer = null;
                    }
                    if (!matchedBtn.classList.contains('sku-props__value--active')) {
                        inner._pmodProgrammaticChange = true;
                        matchedBtn.click();
                    }
                    state.customVolume = null;
                    PModificator.recomputeCustomMode(state);
                    syncUrlPmodVolume(null);

                    // Обновляем лейбл тиража и заголовок h1 с реальным числом
                    var presetStr = v.toLocaleString('ru-RU');
                    if (volumeLabelSpan) {
                        volumeLabelSpan.textContent = presetStr;
                    }
                    PModificator.updateH1WithVolume(presetStr + ' экз');
                } else {
                    // Нет совпадения с пресетом — кастомный режим
                    state.customVolume = v;
                    PModificator.recomputeCustomMode(state);
                    syncUrlPmodVolume(v);

                    if (customBtn) {
                        if (!customBtn.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            customBtn.click();
                        }
                    } else {
                        var nearest = PModificator.findNearestVolumePreset(valuesEl, v);
                        if (nearest && !nearest.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            nearest.click();
                        }
                    }

                    // Обновляем лейбл и h1 с реальным числом (переопределяем "Произвольный тираж").
                    // Immediate set — переопределяет синхронное обновление Аспро после click();
                    // setTimeout — гарантирует сброс при любых async-обновлениях Аспро.
                    var customStr = v.toLocaleString('ru-RU');
                    if (volumeLabelSpan) {
                        volumeLabelSpan.textContent = customStr;
                    }
                    if (state._volumeLabelTimer) {
                        clearTimeout(state._volumeLabelTimer);
                        state._volumeLabelTimer = null;
                    }
                    (function (str) {
                        state._volumeLabelTimer = setTimeout(function () {
                            if (state.customVolume !== v) return;
                            if (volumeLabelSpan) {
                                volumeLabelSpan.textContent = str;
                            }
                            PModificator.updateH1WithVolume(str + ' экз');
                            state._volumeLabelTimer = null;
                        }, PRICE_UPDATE_TIMEOUT_MS);
                    }(customStr));
                }

                PModificator.updatePriceDisplay(container, state);
            }

            var debouncedChange = debounce(function () { onVolumeChange(false); }, DEBOUNCE_MS);
            volumeInput.addEventListener('input', debouncedChange);

            // Валидация при потере фокуса
            volumeInput.addEventListener('blur', function () {
                var raw  = parseInt(volumeInput.value, 10);
                var s    = parseInt(volumeInput.step, 10) || 1;
                var v    = clamp(isNaN(raw) ? minV : raw, minV, maxV);
                v = Math.round(v / s) * s;
                v = clamp(v, minV, maxV);
                volumeInput.value = v;
                onVolumeChange(true);
            });

            ui.querySelectorAll('.pmod-counter__minus, .pmod-counter__plus').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var inp    = btn.closest('.pmod-counter').querySelector('.pmod-counter__input');
                    var curr   = parseInt(inp.value, 10) || parseInt(inp.min, 10);
                    var s      = parseInt(inp.step, 10) || 1;
                    var newVal = btn.classList.contains('pmod-counter__plus') ? curr + s : curr - s;
                    inp.value  = clamp(newVal, parseInt(inp.min, 10), parseInt(inp.max, 10));
                    onVolumeChange(true);
                });
            });

            // Колесико мыши
            volumeInput.addEventListener('wheel', function (e) {
                if (document.activeElement !== volumeInput) return;
                e.preventDefault();
                var current = parseInt(volumeInput.value, 10) || parseInt(volumeInput.min, 10);
                var s       = parseInt(volumeInput.step, 10) || 1;
                var newVal  = e.deltaY < 0 ? current + s : current - s;
                volumeInput.value = clamp(newVal, parseInt(volumeInput.min, 10), parseInt(volumeInput.max, 10));
                onVolumeChange(true);
            }, { passive: false });

            // Сохраняем ссылку для обновления из обработчика кликов
            inner._pmodVolumeInput = volumeInput;
        },

        // ── Слежение за кликами по стандартным пресетам ───────────────────────

        scheduleReapplyCustomPrice: function (container, state) {
            if (state._otherPropRecalcTimer) {
                clearTimeout(state._otherPropRecalcTimer);
                state._otherPropRecalcTimer = null;
            }
            state._otherPropRecalcTimer = setTimeout(function () {
                if (!state.customMode) return;
                PModificator.updatePriceDisplay(container, state);
                state._otherPropRecalcTimer = null;
            }, PRICE_UPDATE_TIMEOUT_MS + 120);
        },

        watchPresetClicks: function (container, state) {
            var productCfg = window.pmodConfig && window.pmodConfig.products && window.pmodConfig.products[state.productId];
            var formatPropId = productCfg && productCfg.formatPropId;
            var volumePropId = productCfg && productCfg.volumePropId;

            container.addEventListener('click', function (e) {
                var btn = e.target.closest('.sku-props__value');
                if (!btn) return;

                // Определяем, к какому свойству относится кнопка
                var innerEl = btn.closest('.sku-props__inner');
                if (!innerEl) return;
                var propId = innerEl.dataset.id;

                // Обрабатываем только клики на кнопки FORMAT или VOLUME свойств
                if (String(propId) === String(formatPropId)) {
                    // Если клик был вызван программно из onFormatChange — не перезаписываем инпуты и не трогаем state
                    if (innerEl._pmodProgrammaticChange) {
                        innerEl._pmodProgrammaticChange = false;
                        return;
                    }
                    var wInput = innerEl._pmodWidthInput;
                    var hInput = innerEl._pmodHeightInput;
                    var enumId   = btn.dataset.onevalue || '';
                    var fmtXmlId = (state.formatEnumMap && enumId && state.formatEnumMap[enumId] !== undefined)
                        ? state.formatEnumMap[enumId]
                        : (btn.dataset.title || '');

                    if (fmtXmlId === 'X') {
                        // Клик по «Произвольный формат» — не обновлять инпуты, включить custom mode
                        state.customWidth  = wInput ? (parseInt(wInput.value, 10) || null) : null;
                        state.customHeight = hInput ? (parseInt(hInput.value, 10) || null) : null;
                        PModificator.recomputeCustomMode(state);
                        PModificator.updatePriceDisplay(container, state);
                    } else {
                        // Клик по пресету FORMAT — обновляем поля ширины/высоты
                        if (wInput && hInput) {
                            var parts = fmtXmlId.toLowerCase().split('x');
                            if (parts.length === 2) {
                                wInput.value = parseInt(parts[0], 10) || wInput.value;
                                hInput.value = parseInt(parts[1], 10) || hInput.value;
                            }
                        }
                        state.customWidth  = null;
                        state.customHeight = null;
                        PModificator.recomputeCustomMode(state);
                        PModificator.hideCustomPrice(container);
                    }

                } else if (String(propId) === String(volumePropId)) {
                    // Если клик был вызван программно из onVolumeChange — не перезаписываем инпут и не трогаем state
                    if (innerEl._pmodProgrammaticChange) {
                        innerEl._pmodProgrammaticChange = false;
                        return;
                    }
                    var vInput = innerEl._pmodVolumeInput;
                    var enumId     = btn.dataset.onevalue || '';
                    var rawVolXmlId = (state.volumeEnumMap && enumId && state.volumeEnumMap[enumId] !== undefined)
                        ? state.volumeEnumMap[enumId]
                        : (btn.dataset.title || '');

                    if (rawVolXmlId === 'X') {
                        // Клик по «Произвольный тираж» — включить custom mode
                        state.customVolume = vInput ? (parseInt(vInput.value, 10) || null) : null;
                        PModificator.recomputeCustomMode(state);
                        PModificator.updatePriceDisplay(container, state);
                    } else {
                        // Клик по пресету VOLUME — обновляем поле тиража
                        var volXmlId = parseInt(rawVolXmlId, 10);
                        if (vInput && !isNaN(volXmlId)) {
                            vInput.value = volXmlId;
                        }
                        state.customVolume = null;
                        PModificator.recomputeCustomMode(state);
                        if (state._volumeLabelTimer) {
                            clearTimeout(state._volumeLabelTimer);
                            state._volumeLabelTimer = null;
                        }
                        syncUrlPmodVolume(null);
                        PModificator.hideCustomPrice(container);

                        // Обновляем лейбл тиража и h1 (Аспро обновит textContent,
                        // MutationObserver синхронизирует title; но также страхуемся явной установкой)
                        if (!isNaN(volXmlId)) {
                            var presetLabelStr = volXmlId.toLocaleString('ru-RU');
                            var volumeInnerEl  = innerEl;
                            var labelSpan = volumeInnerEl.querySelector('.sku-props__title .sku-props__js-size');
                            if (labelSpan) {
                                labelSpan.textContent = presetLabelStr;
                            }
                            PModificator.updateH1WithVolume(presetLabelStr + ' экз');
                        }
                    }

                } else if (state.allPropIds && state.allPropIds.indexOf(parseInt(propId, 10)) !== -1) {
                    // Клик по «прочему» свойству (красочность, бумага и т.д.) — обновляем activeOtherProps
                    var otherEnumId = parseInt(btn.dataset.onevalue, 10);
                    if (!isNaN(otherEnumId)) {
                        state.activeOtherProps[parseInt(propId, 10)] = otherEnumId;
                    }
                    if (state.customMode) {
                        // 1) мгновенная переоценка
                        PModificator.updatePriceDisplay(container, state);
                        // 2) отложенная — после асинхронного DOM/AJAX-апдейта Аспро,
                        // чтобы не оставалась "техническая" цена X-ТП
                        PModificator.scheduleReapplyCustomPrice(container, state);
                    }
                }

            }, true); // capture — срабатывает до skuAction.js
        },

        // ── Поиск пресетов ────────────────────────────────────────────────────

        /**
         * Найти кнопку с XML_ID="X" (произвольное значение) в списке кнопок ТП.
         * @param {Element} valuesEl
         * @param {Object}  enumMap  — маппинг enumId → xmlId (строка)
         * @returns {Element|null}
         */
        findCustomButton: function (valuesEl, enumMap) {
            var found = null;
            valuesEl.querySelectorAll('.sku-props__value').forEach(function (btn) {
                var eid = btn.dataset.onevalue || '';
                if (enumMap && eid && enumMap[eid] === 'X') {
                    found = btn;
                }
            });
            return found;
        },

        findFormatPreset: function (valuesEl, w, h, formatEnumMap) {
            var target = w + 'x' + h;
            var btns = valuesEl.querySelectorAll('.sku-props__value');
            for (var i = 0; i < btns.length; i++) {
                var eid    = btns[i].dataset.onevalue || '';
                var xmlId  = (formatEnumMap && eid && formatEnumMap[eid] !== undefined)
                    ? formatEnumMap[eid]
                    : (btns[i].dataset.onevalue || btns[i].dataset.title || '');
                var title  = (btns[i].dataset.title || '').toLowerCase().replace(/[^0-9x]/g, '');
                if (xmlId.toLowerCase() === target.toLowerCase() || title === target.toLowerCase()) {
                    return btns[i];
                }
            }
            return null;
        },

        findNearestFormatPreset: function (valuesEl, w, h, formatEnumMap) {
            var area = w * h;
            var best = null, bestDist = Infinity;
            valuesEl.querySelectorAll('.sku-props__value').forEach(function (btn) {
                var eid   = btn.dataset.onevalue || '';
                var xmlId = (formatEnumMap && eid && formatEnumMap[eid] !== undefined)
                    ? formatEnumMap[eid]
                    : (btn.dataset.title || '');
                var parts = xmlId.toLowerCase().split('x');
                if (parts.length !== 2) return;
                var bw = parseInt(parts[0], 10);
                var bh = parseInt(parts[1], 10);
                if (isNaN(bw) || isNaN(bh)) return;
                var d = Math.abs(bw * bh - area);
                if (d < bestDist) { bestDist = d; best = btn; }
            });
            return best;
        },

        findVolumePreset: function (valuesEl, v, volumeEnumMap) {
            var btns = valuesEl.querySelectorAll('.sku-props__value');
            for (var i = 0; i < btns.length; i++) {
                var eid = btns[i].dataset.onevalue || '';
                var xmlId = (volumeEnumMap && eid && volumeEnumMap[eid] !== undefined)
                    ? parseInt(volumeEnumMap[eid], 10)
                    : parseInt(btns[i].dataset.title, 10);
                if (xmlId === v) return btns[i];
            }
            return null;
        },

        findNearestVolumePreset: function (valuesEl, v) {
            var best = null, bestDist = Infinity;
            valuesEl.querySelectorAll('.sku-props__value').forEach(function (btn) {
                var t = parseInt(btn.dataset.title, 10);
                if (isNaN(t)) return;
                var d = Math.abs(t - v);
                if (d < bestDist) { bestDist = d; best = btn; }
            });
            return best;
        },

        // ── Фильтрация предложений по активным свойствам ──────────────────────

        /**
         * Фильтрует массив предложений по словарю {propId: enumId}.
         * Возвращает только те предложения, у которых для каждого propId
         * значение props[propId] совпадает с требуемым enumId.
         *
         * @param {Array}  offers           — массив из pmodConfig
         * @param {Object} activeOtherProps — {propId: enumId}
         * @returns {Array}
         */
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

        /**
         * Интерполирует цены для всех групп × диапазонов по заданному тиражу.
         *
         * Результат: {groupId: [{from, to, price}, ...]}
         * Использует только предложения с числовым volume (исключает X-ТП и плейсхолдеры ≤ 0).
         *
         * @param {Array}  offers        — предложения (уже отфильтрованные по props)
         * @param {number} volume        — запрошенный тираж
         * @param {Object} roundingRules — {groupId: [{price, type, precision}, ...]} (опционально)
         * @returns {Object}
         */
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

                    var price = linearInterp(pts, volume);
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

        // ── Отображение расчётной цены ────────────────────────────────────────

        updatePriceDisplay: function (container, state) {
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

            // Если AJAX-URL настроен — уточняем цену на сервере
            var cfg = window.pmodConfig;
            if (cfg && cfg.ajaxUrl) {
                // Для произвольного тиража работаем по схеме server-first:
                // показываем лоадер и применяем только финальную серверную цену.
                var serverFirst = state.customVolume !== null;
                if (serverFirst) {
                    var visiblePopup = PModificator.getVisiblePopupPriceElement();
                    if (visiblePopup) {
                        visiblePopup.classList.add('pmod-price-loading');
                    }
                } else {
                    // Для прочих сценариев оставляем оптимистичный UI.
                    PModificator.showCustomPrice(container, interpolated, state);
                }

                PModificator.fetchServerPrice(state, function (data) {
                    if (!state.customMode) return;
                    if (!data || !data.success) {
                        // Fallback при ошибке сервера: показываем клиентский расчёт и снимаем лоадер.
                        PModificator.showCustomPrice(container, interpolated, state);
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
                        if (srvMain !== null) {
                            state.lastCalculatedPrice = srvMain;
                        }
                    }

                    // Применяем серверные цены к DOM
                    PModificator.applyServerPricesToDom(container, serverInterpolated, state);
                });
                return;
            }

            // Если серверного уточнения нет — показываем клиентский расчёт.
            PModificator.showCustomPrice(container, interpolated, state);
        },

        getVisiblePopupPriceElement: function () {
            var popupPrice = null;
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                if (el.offsetParent !== null || el.offsetHeight > 0) {
                    popupPrice = el;
                }
            });
            return popupPrice;
        },

        /**
         * Возвращает цену базовой группы (или первой доступной), первый диапазон.
         */
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

        // ── Серверный пересчёт цены (AJAX) ───────────────────────────────────

        /**
         * Отправляет AJAX-запрос на сервер для точного пересчёта цены.
         *
         * Защита от race conditions:
         *  - Отменяет предыдущий запрос через AbortController
         *  - Сравнивает state._ajaxRequestId перед применением результата
         *
         * @param {Object}   state    — состояние контейнера
         * @param {Function} callback — fn(data|null)
         */
        fetchServerPrice: function (state, callback) {
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

            var body = new FormData();
            body.append('productId', state.productId);
            body.append('basket_qty', PModificator.getBasketQuantity(state.productId));
            var visibleGroups = PModificator.getVisiblePriceGroupIds(state);
            if (visibleGroups.length) {
                visibleGroups.forEach(function (gid) {
                    body.append('visible_groups[]', gid);
                });
            }
            if (state.customVolume)  body.append('volume',  state.customVolume);
            if (state.customWidth)   body.append('width',   state.customWidth);
            if (state.customHeight)  body.append('height',  state.customHeight);

            if (state.activeOtherProps) {
                Object.keys(state.activeOtherProps).forEach(function (propId) {
                    body.append('other_props[' + propId + ']', state.activeOtherProps[propId]);
                });
            }

            // CSRF-токен Bitrix
            var sessid = (typeof BX !== 'undefined' && BX.bitrix_sessid)
                ? BX.bitrix_sessid()
                : ((typeof window.bitrix_sessid !== 'undefined') ? window.bitrix_sessid : '');
            if (sessid) {
                body.append('sessid', sessid);
            }

            var fetchOpts = { method: 'POST', body: body };
            if (abortCtrl) {
                fetchOpts.signal = abortCtrl.signal;
            }

            fetch(ajaxUrl, fetchOpts)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    // Игнорируем устаревший ответ (защита от race conditions)
                    if (state._ajaxRequestId !== requestId) return;
                    state._ajaxAbortCtrl = null;
                    callback(data);
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    console.warn('[pmod] Server price fetch error:', e);
                    callback(null);
                });
        },

        /**
         * Пытается определить текущее количество "тиражей" (quantity) на карточке.
         * Если не найдено — возвращает 1.
         */
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

        /**
         * Применяет серверные цены непосредственно в DOM, минуя MutationObserver.
         * Вызывается когда сервер вернул финальный расчёт.
         *
         * @param {Element} container
         * @param {Object}  interpolated — {groupId: [{from, to, price}]}
         * @param {Object}  state
         */
        applyServerPricesToDom: function (container, interpolated, state) {
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
        },

        /**
         * Показать расчётную цену в блоке цены Аспро.
         *
         * Anti-flicker алгоритм:
         *  1. Немедленно добавляем pmod-price-loading на .js-popup-price (скрывает цену)
         *  2. Устанавливаем MutationObserver — ждём, пока Аспро обновит DOM через AJAX
         *  3. Когда DOM изменился (или по таймауту) — подставляем нашу цену и снимаем класс
         *
         * @param {Element} container
         * @param {Object}  interpolated — {groupId: [{from, to, price}, ...]}
         * @param {Object}  state
         */
        showCustomPrice: function (container, interpolated, state) {
            // Ищем видимый .js-popup-price (на странице их может быть несколько —
            // один скрытый от предыдущего ТП, один видимый от X-ТП)
            var popupPrice = null;
            document.querySelectorAll('.js-popup-price').forEach(function (el) {
                if (el.offsetParent !== null || el.offsetHeight > 0) {
                    popupPrice = el;
                }
            });
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
                observer.disconnect();
                applyPrices();
            });
            observer.observe(popupPrice, { childList: true, subtree: true, characterData: true });
            popupPrice._pmodObserver = observer;

            // Fallback-таймаут: если Аспро не обновит DOM (X-ТП уже активен),
            // применяем цены через PRICE_UPDATE_TIMEOUT_MS
            popupPrice._pmodFallbackTimer = setTimeout(function () {
                if (popupPrice._pmodObserver) {
                    popupPrice._pmodObserver.disconnect();
                    delete popupPrice._pmodObserver;
                }
                applyPrices();
            }, PRICE_UPDATE_TIMEOUT_MS);
        },

        /**
         * Отменить ожидающее применение цен (очистить observer + timer).
         */
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

        /**
         * Применяет интерполированные цены в DOM блока .js-popup-price.
         *
         * Стратегия (Aspro Premier DOM):
         *  1. Определяем порядок групп из data-price-config (PRICE_CODE) или по числовому ключу
         *  2. Строим плоский список цен: [g1_range1, g1_range2, ..., g2_range1, ...]
         *  3. Находим все .price__new-val внутри template.xpopover-template → popup-таблица
         *  4. Обновляем их позиционно
         *  5. Обновляем главную видимую цену (снаружи template, внутри .price__popup-toggle)
         */
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
        },

        // ── Перехват корзины ──────────────────────────────────────────────────

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

        /**
         * Патч JItemActionBasket.prototype.collectRequestData —
         * добавляем поля prospekt_calc[...] если активен кастомный режим.
         */
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

        /**
         * Надёжный fallback-перехват корзины через monkey-patching window.fetch.
         *
         * Работает с любой версией Aspro Premier независимо от наличия
         * JItemActionBasket. Перед добавлением полей проверяет has() чтобы
         * не дублировать данные, если patchCollectRequestData уже отработал.
         *
         * Для поддержки нескольких контейнеров на одной странице реестр состояний
         * хранится в window._pmodActiveStates. Подключение fetch-хука выполняется
         * один раз (флаг window._pmodFetchHooked).
         *
         * @param {Object} state — состояние контейнера
         */
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
        },
    };

    // ── Запуск ────────────────────────────────────────────────────────────────

    ready(function () {
        PModificator.init();
    });

    // Экспортируем для возможного внешнего использования
    window.PModificator = PModificator;

})();
