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

    var DEBOUNCE_MS = 400;

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

    function formatPrice(price) {
        return price.toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + ' ₽';
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

            containers.forEach(function (container) {
                PModificator.initContainer(container, cfg);
            });
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

            var state = {
                productId:    productId,
                offers:       productCfg.offers || [],
                formatCfg:    productCfg.formatSettings || {},
                volumeCfg:    productCfg.volumeSettings || {},
                volumeEnumMap: productCfg.volumeEnumMap || {},
                formatEnumMap: productCfg.formatEnumMap || {},
                customWidth:  null,
                customHeight: null,
                customVolume: null,
                customMode:   false,
            };

            // Найти блоки свойств
            if (formatPropId) {
                var formatInner = container.querySelector('.sku-props__inner[data-id="' + formatPropId + '"]');
                if (formatInner) {
                    PModificator.enhanceFormatProp(formatInner, state, container);
                }
            }

            if (volumePropId) {
                var volumeInner = container.querySelector('.sku-props__inner[data-id="' + volumePropId + '"]');
                if (volumeInner) {
                    PModificator.enhanceVolumeProp(volumeInner, state, container);
                }
            }

            // Следим за кликами по стандартным кнопкам ТП
            PModificator.watchPresetClicks(container, state);

            // Перехватываем отправку корзины
            PModificator.hookBasket(container, state);
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

            function onFormatChange() {
                var w = clamp(parseInt(widthInput.value, 10)  || minW, minW, maxW);
                var h = clamp(parseInt(heightInput.value, 10) || minH, minH, maxH);
                widthInput.value  = w;
                heightInput.value = h;

                // Ищем точное совпадение с пресетом
                var matched = PModificator.findFormatPreset(valuesEl, w, h, state.formatEnumMap);
                if (matched) {
                    // Кликаем на пресет — стандартная логика Аспро
                    if (!matched.classList.contains('sku-props__value--active')) {
                        matched.click();
                    }
                    state.customWidth  = null;
                    state.customHeight = null;
                    state.customMode   = !state.customVolume;
                } else {
                    state.customWidth  = w;
                    state.customHeight = h;
                    state.customMode   = true;

                    // Выбираем ближайший пресет
                    var nearest = PModificator.findNearestFormatPreset(valuesEl, w, h, state.formatEnumMap);
                    if (nearest && !nearest.classList.contains('sku-props__value--active')) {
                        nearest.click();
                    }
                }

                PModificator.updatePriceDisplay(container, state);
            }

            var debouncedChange = debounce(onFormatChange, DEBOUNCE_MS);

            widthInput.addEventListener('input',  debouncedChange);
            heightInput.addEventListener('input', debouncedChange);

            // +/- кнопки
            ui.querySelectorAll('.pmod-counter__minus, .pmod-counter__plus').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var inp     = btn.closest('.pmod-counter').querySelector('.pmod-counter__input');
                    var current = parseInt(inp.value, 10) || parseInt(inp.min, 10);
                    var s       = parseInt(inp.step, 10) || 1;
                    var newVal  = btn.classList.contains('pmod-counter__plus') ? current + s : current - s;
                    inp.value   = clamp(newVal, parseInt(inp.min, 10), parseInt(inp.max, 10));
                    inp.dispatchEvent(new Event('input'));
                });
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
                initXmlId = (volumeEnumMap && ae && volumeEnumMap[ae] !== undefined)
                    ? parseInt(volumeEnumMap[ae], 10)
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
            ui.appendChild(valuesEl);

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
            function onVolumeChange() {
                var v = clamp(parseInt(volumeInput.value, 10) || minV, minV, maxV);
                volumeInput.value = v;

                var matchedBtn = findBtnByXmlId(v);
                if (matchedBtn) {
                    if (!matchedBtn.classList.contains('sku-props__value--active')) {
                        matchedBtn.click();
                    }
                    state.customVolume = null;
                    state.customMode   = !!state.customWidth;
                } else {
                    // Нет совпадения с пресетом — кастомный режим
                    state.customVolume = v;
                    state.customMode   = true;

                    var nearest = PModificator.findNearestVolumePreset(valuesEl, v);
                    if (nearest && !nearest.classList.contains('sku-props__value--active')) {
                        nearest.click();
                    }
                }

                PModificator.updatePriceDisplay(container, state);
            }

            var debouncedChange = debounce(onVolumeChange, DEBOUNCE_MS);
            volumeInput.addEventListener('input', debouncedChange);

            ui.querySelectorAll('.pmod-counter__minus, .pmod-counter__plus').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var inp    = btn.closest('.pmod-counter').querySelector('.pmod-counter__input');
                    var curr   = parseInt(inp.value, 10) || parseInt(inp.min, 10);
                    var s      = parseInt(inp.step, 10) || 1;
                    var newVal = btn.classList.contains('pmod-counter__plus') ? curr + s : curr - s;
                    inp.value  = clamp(newVal, parseInt(inp.min, 10), parseInt(inp.max, 10));
                    inp.dispatchEvent(new Event('input'));
                });
            });

            // Сохраняем ссылку для обновления из обработчика кликов
            inner._pmodVolumeInput = volumeInput;
        },

        // ── Слежение за кликами по стандартным пресетам ───────────────────────

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
                    // Клик по пресету FORMAT — обновляем поля ширины/высоты
                    var wInput = innerEl._pmodWidthInput;
                    var hInput = innerEl._pmodHeightInput;
                    if (wInput && hInput) {
                        var enumId   = btn.dataset.onevalue || '';
                        var fmtXmlId = (state.formatEnumMap && enumId && state.formatEnumMap[enumId] !== undefined)
                            ? state.formatEnumMap[enumId]
                            : (btn.dataset.title || '');
                        var parts = fmtXmlId.toLowerCase().split('x');
                        if (parts.length === 2) {
                            wInput.value = parseInt(parts[0], 10) || wInput.value;
                            hInput.value = parseInt(parts[1], 10) || hInput.value;
                        }
                    }
                    state.customMode   = false;
                    state.customWidth  = null;
                    state.customHeight = null;
                    PModificator.hideCustomPrice(container);

                } else if (String(propId) === String(volumePropId)) {
                    // Клик по пресету VOLUME — обновляем поле тиража
                    var vInput = innerEl._pmodVolumeInput;
                    if (vInput) {
                        var enumId   = btn.dataset.onevalue || '';
                        var volXmlId = (state.volumeEnumMap && enumId && state.volumeEnumMap[enumId] !== undefined)
                            ? parseInt(state.volumeEnumMap[enumId], 10)
                            : (parseInt(btn.dataset.title, 10) || NaN);
                        if (!isNaN(volXmlId)) {
                            vInput.value = volXmlId;
                        }
                    }
                    state.customMode   = false;
                    state.customVolume = null;
                    PModificator.hideCustomPrice(container);
                }
                // Если это другое свойство (красочность, бумага и т.д.) — ничего не делаем

            }, true); // capture — срабатывает до skuAction.js
        },

        // ── Поиск пресетов ────────────────────────────────────────────────────

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

        // ── Отображение расчётной цены ────────────────────────────────────────

        updatePriceDisplay: function (container, state) {
            if (!state.customMode) {
                PModificator.hideCustomPrice(container);
                return;
            }

            var offers  = state.offers;
            var w       = state.customWidth;
            var h       = state.customHeight;
            var v       = state.customVolume;

            var price = null;

            if (w && h && v) {
                price = bilinearInterp(offers, w, h, v);
            } else if (w && h) {
                // Только формат: линейная по площади
                var area = w * h;
                var areaPoints = offers
                    .filter(function (o) { return o.width && o.height && o.price; })
                    .map(function (o) { return { key: o.width * o.height, price: o.price }; })
                    .sort(function (a, b) { return a.key - b.key; });
                price = linearInterp(areaPoints, area);
            } else if (v) {
                // Только тираж: линейная по тиражу
                var volPoints = offers
                    .filter(function (o) { return o.volume && o.price; })
                    .map(function (o) { return { key: o.volume, price: o.price }; })
                    .sort(function (a, b) { return a.key - b.key; });
                price = linearInterp(volPoints, v);
            }

            console.log('[pmod price]', {
                productId: state.productId,
                width: w,
                height: h,
                volume: v,
                offersCount: offers.length,
                calculatedPrice: price,
            });

            if (price === null) {
                PModificator.hideCustomPrice(container);
                return;
            }

            state.lastCalculatedPrice = price;
            PModificator.showCustomPrice(container, price);
        },

        showCustomPrice: function (container, price) {
            var priceEl = container.querySelector('.pmod-custom-price');

            if (!priceEl) {
                priceEl = document.createElement('div');
                priceEl.className = 'pmod-custom-price';

                // Вставляем после блока .sku-props или перед кнопкой корзины
                var buyBtn = document.querySelector('.detail-buy, .js-basket-btn, [data-entity="basket-button"]');
                var insertTarget = buyBtn ? buyBtn.parentNode : container.parentNode;
                if (insertTarget) {
                    insertTarget.insertBefore(priceEl, buyBtn);
                }
            }

            priceEl.innerHTML =
                '<span class="pmod-custom-price__label">Расчётная цена:</span> ' +
                '<span class="pmod-custom-price__value">' + formatPrice(price) + '</span>' +
                '<span class="pmod-custom-price__note"> (предварительный расчёт)</span>';
            priceEl.style.display = '';
        },

        hideCustomPrice: function (container) {
            var priceEl = document.querySelector('.pmod-custom-price');
            if (priceEl) priceEl.style.display = 'none';
        },

        // ── Перехват корзины ──────────────────────────────────────────────────

        hookBasket: function (container, state) {
            // Ждём инициализации JItemActionBasket (может грузиться асинхронно)
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

                if (state.lastCalculatedPrice) {
                    formData.append('prospekt_calc[custom_price]', state.lastCalculatedPrice.toFixed(2));
                }

                return formData;
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
