/**
 * PModControls module.
 */
;(function () {
    'use strict';

    var utils = window.PModUtils || {};
    var clamp = utils.clamp || function (val, min, max) { return Math.max(min, Math.min(max, val)); };
    var debounce = utils.debounce || function (fn) { return fn; };
    var syncUrlPmodVolume = utils.syncUrlPmodVolume || function () {};
    var hasNumberValue = utils.hasNumberValue || function (value) { return value !== null && value !== undefined; };
    var DEBOUNCE_MS = 300;
    var PRICE_UPDATE_TIMEOUT_MS = 400;

    window.PModControls = {
        enhanceFormatProp: function (inner, state, container) {
            var valuesEl  = inner.querySelector('.sku-props__values');
            if (!valuesEl) return;

            var fmtCfg = state.formatCfg;
            var minW   = fmtCfg.MIN_WIDTH  || 10;
            var maxW   = fmtCfg.MAX_WIDTH  || 1200;
            var minH   = fmtCfg.MIN_HEIGHT || 10;
            var maxH   = fmtCfg.MAX_HEIGHT || 1200;
            var step   = fmtCfg.STEP       || 1;
            var formatMeasure = fmtCfg.MEASURE || 'мм';
            var showFormatMeasure = fmtCfg.SHOW_MEASURE === 'Y';
            var rawInputLabels = Array.isArray(fmtCfg.FORMAT_INPUT_LABELS) ? fmtCfg.FORMAT_INPUT_LABELS : [];
            var widthLabel = String(rawInputLabels[0] || '').trim() || 'Параметр 1';
            var heightLabel = String(rawInputLabels[1] || '').trim() || 'Параметр 2';

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
                    '<label class="pmod-label">' + widthLabel + (showFormatMeasure ? ', ' + formatMeasure : '') + '</label>',
                    '<div class="pmod-counter">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__minus" aria-label="Уменьшить параметр 1">&#8722;</button>',
                      '<input type="number" class="pmod-counter__input pmod-input-width"',
                             ' min="' + minW + '" max="' + maxW + '" step="' + step + '"',
                             ' value="' + initW + '" autocomplete="off" aria-label="' + widthLabel + '">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__plus" aria-label="Увеличить параметр 1">+</button>',
                    '</div>',
                  '</div>',
                  '<span class="pmod-format-x">&#215;</span>',
                  '<div class="pmod-input-group">',
                    '<label class="pmod-label">' + heightLabel + (showFormatMeasure ? ', ' + formatMeasure : '') + '</label>',
                    '<div class="pmod-counter">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__minus" aria-label="Уменьшить параметр 2">&#8722;</button>',
                      '<input type="number" class="pmod-counter__input pmod-input-height"',
                             ' min="' + minH + '" max="' + maxH + '" step="' + step + '"',
                             ' value="' + initH + '" autocomplete="off" aria-label="' + heightLabel + '">',
                      '<button type="button" class="pmod-counter__btn pmod-counter__plus" aria-label="Увеличить параметр 2">+</button>',
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
                var didTriggerSkuSwitch = false;

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
                        didTriggerSkuSwitch = true;
                    }
                    state.customWidth  = null;
                    state.customHeight = null;
                    PModificator.setCustomValuesForSkuCode(state, state.formatPropCode, null);
                    PModificator.recomputeCustomMode(state);
                } else {
                    state.customWidth  = w;
                    state.customHeight = h;
                    PModificator.setCustomValuesForSkuCode(state, state.formatPropCode, [w, h]);
                    PModificator.recomputeCustomMode(state);

                    // Выбираем кнопку «Произвольный формат» или ближайший пресет
                    if (customBtn) {
                        if (!customBtn.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            customBtn.click();
                            didTriggerSkuSwitch = true;
                        }
                    } else {
                        var nearest = PModificator.findNearestFormatPreset(valuesEl, w, h, state.formatEnumMap);
                        if (nearest && !nearest.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            nearest.click();
                            didTriggerSkuSwitch = true;
                        }
                    }
                }

                PModificator.registerCustomPropertyChange(state, didTriggerSkuSwitch);
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

        enhanceVolumeProp: function (inner, state, container) {
            var valuesEl = inner.querySelector('.sku-props__values');
            if (!valuesEl) return;

            var volCfg        = state.volumeCfg;
            var volumeEnumMap = state.volumeEnumMap; // enumId → xmlIdNumber
            var minV   = volCfg.MIN  || 10;
            var maxV   = volCfg.MAX  || 100000;
            var step   = volCfg.STEP || 1;
            var volumeMeasure = volCfg.MEASURE || 'шт';
            var showVolumeMeasure = volCfg.SHOW_MEASURE === 'Y';
            var hidePresetButtons = volCfg.HIDE_PRESET_BUTTONS === 'Y';

            // Скрываем стандартные кнопки пресетов только если включен флаг
            if (hidePresetButtons) {
                valuesEl.classList.add('pmod-preset-buttons--hidden');
            }

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
                  (showVolumeMeasure ? '<span class="pmod-volume-measure">' + volumeMeasure + '</span>' : ''),
                '</div>',
            ].join('');

            valuesEl.parentNode.insertBefore(ui, valuesEl);

            var volumeInput = ui.querySelector('.pmod-input-volume');

            function setVolumeUiMarginByPresetVisibility() {
                var hasVisiblePresetButtons = !hidePresetButtons || !valuesEl.classList.contains('pmod-preset-buttons--hidden');
                ui.classList.toggle('pmod-volume-ui--with-options', hasVisiblePresetButtons);
            }

            setVolumeUiMarginByPresetVisibility();

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

            function clearVolumeLabelTimer() {
                if (state._volumeLabelTimer) {
                    clearTimeout(state._volumeLabelTimer);
                    state._volumeLabelTimer = null;
                }
            }

            function isVolumeLabelUpdateActual(localRevision, expectedCustomVolume) {
                if (!PModificator.isRevisionActual(state, localRevision)) return false;
                if (state.customVolume !== expectedCustomVolume) return false;

                var root = document.documentElement;
                if (!root || !root.contains(inner) || !root.contains(container)) return false;
                if (volumeLabelSpan && !root.contains(volumeLabelSpan)) return false;
                return true;
            }

            function scheduleVolumeLabelUpdate(labelStr, expectedCustomVolume) {
                var localRevision = state._uiRevision;
                clearVolumeLabelTimer();

                state._volumeLabelTimer = setTimeout(function () {
                    if (!isVolumeLabelUpdateActual(localRevision, expectedCustomVolume)) {
                        state._volumeLabelTimer = null;
                        return;
                    }
                    if (volumeLabelSpan) {
                        volumeLabelSpan.textContent = labelStr;
                    }
                    state._volumeLabelTimer = null;
                }, PRICE_UPDATE_TIMEOUT_MS);
            }

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
            if (hidePresetButtons) {
                volumeInput.addEventListener('focus', function () {
                    valuesEl.classList.remove('pmod-preset-buttons--hidden');
                    valuesEl.classList.add('pmod-preset-buttons--floating');
                    setVolumeUiMarginByPresetVisibility();
                });

                volumeInput.addEventListener('blur', function () {
                    setTimeout(function () {
                        valuesEl.classList.add('pmod-preset-buttons--hidden');
                        valuesEl.classList.remove('pmod-preset-buttons--floating');
                        setVolumeUiMarginByPresetVisibility();
                    }, 200);
                });
            }

            // ── Обработчик изменения инпута ────────────────────────────────────
            function onVolumeChange(isImmediate) {
                var raw = parseInt(volumeInput.value, 10);
                var v;
                var didTriggerSkuSwitch = false;

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
                    clearVolumeLabelTimer();
                    if (!matchedBtn.classList.contains('sku-props__value--active')) {
                        inner._pmodProgrammaticChange = true;
                        matchedBtn.click();
                        didTriggerSkuSwitch = true;
                    }
                    state.customVolume = null;
                    PModificator.setCustomValuesForSkuCode(state, state.volumePropCode, null);
                    PModificator.recomputeCustomMode(state);
                    syncUrlPmodVolume(null);

                    // Обновляем лейбл тиража и заголовок h1 с реальным числом
                    var presetStr = v.toLocaleString('ru-RU');
                    if (volumeLabelSpan) {
                        volumeLabelSpan.textContent = presetStr;
                    }
                } else {
                    // Нет совпадения с пресетом — кастомный режим
                    state.customVolume = v;
                    PModificator.setCustomValuesForSkuCode(state, state.volumePropCode, [v]);
                    PModificator.recomputeCustomMode(state);
                    syncUrlPmodVolume(v);

                    if (customBtn) {
                        if (!customBtn.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            customBtn.click();
                            didTriggerSkuSwitch = true;
                        }
                    } else {
                        var nearest = PModificator.findNearestVolumePreset(valuesEl, v, state.volumeEnumMap);
                        if (nearest && !nearest.classList.contains('sku-props__value--active')) {
                            inner._pmodProgrammaticChange = true;
                            nearest.click();
                            didTriggerSkuSwitch = true;
                        }
                    }

                    // Обновляем лейбл и h1 с реальным числом (переопределяем "Произвольный тираж").
                    // Immediate set — переопределяет синхронное обновление Аспро после click();
                    // setTimeout — гарантирует сброс при любых async-обновлениях Аспро.
                    var customStr = v.toLocaleString('ru-RU');
                    if (volumeLabelSpan) {
                        volumeLabelSpan.textContent = customStr;
                    }
                    scheduleVolumeLabelUpdate(customStr, v);
                }

                PModificator.registerCustomPropertyChange(state, didTriggerSkuSwitch);
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

        applyFinalUiState: function (state) {
            if (!state || !state.containerEl) return;

            var h1 = PModificator.getH1Element();
            var title = state.customMode ? (state.renderedCustomTitle || '').trim() : '';
            if (h1 && title) {
                h1._pmodUpdatingTitle = true;
                h1.textContent = title;
                h1.title = title;
                h1._pmodUpdatingTitle = false;
            } else if (h1 && !state.customMode) {
                // Для опорных (некастомных) ТП не вмешиваемся в h1/textContent:
                // штатное название должно приходить из Aspro без подмен со стороны pmod.
                var nativeTitle = (h1.textContent || '').trim();
                if (nativeTitle && h1.title !== nativeTitle) {
                    h1.title = nativeTitle;
                }
            }

            if (state.customMode) {
                PModificator.updatePriceDisplay(state.containerEl, state, state._activeUiRevision);
            } else {
                PModificator.hideCustomPrice(state.containerEl);
                PModificator.setPriceLoading(false);
            }

            if (state._uiStabilizationTimer) {
                clearTimeout(state._uiStabilizationTimer);
                state._uiStabilizationTimer = null;
            }
            state._pendingUiUpdate = false;
            PModificator.setTitleLoading(false);
        },

        watchPresetClicks: function (container, state) {
            var productCfg = window.pmodConfig && window.pmodConfig.products && window.pmodConfig.products[state.productId];
            var formatPropId = productCfg && productCfg.formatPropId;
            var volumePropId = productCfg && productCfg.volumePropId;

            container.addEventListener('click', function (e) {
                var btn = e.target.closest('.sku-props__value');
                if (!btn) return;
                var shouldWaitForAspro = !btn.classList.contains('sku-props__value--active');

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
                        if (hasNumberValue(state.customWidth) && hasNumberValue(state.customHeight)) {
                            PModificator.setCustomValuesForSkuCode(state, state.formatPropCode, [state.customWidth, state.customHeight]);
                        }
                        PModificator.recomputeCustomMode(state);
                        state._pendingUiUpdate = true;
                        PModificator.registerCustomPropertyChange(state, shouldWaitForAspro);
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
                        PModificator.setCustomValuesForSkuCode(state, state.formatPropCode, null);
                        PModificator.recomputeCustomMode(state);
                        state._pendingUiUpdate = true;
                        PModificator.registerCustomPropertyChange(state, shouldWaitForAspro);
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
                        if (hasNumberValue(state.customVolume)) {
                            PModificator.setCustomValuesForSkuCode(state, state.volumePropCode, [state.customVolume]);
                        }
                        PModificator.recomputeCustomMode(state);
                        state._pendingUiUpdate = true;
                        PModificator.registerCustomPropertyChange(state, shouldWaitForAspro);
                    } else {
                        // Клик по пресету VOLUME — обновляем поле тиража
                        var volXmlId = parseInt(rawVolXmlId, 10);
                        if (vInput && !isNaN(volXmlId)) {
                            vInput.value = volXmlId;
                        }
                        state.customVolume = null;
                        PModificator.setCustomValuesForSkuCode(state, state.volumePropCode, null);
                        PModificator.recomputeCustomMode(state);
                        if (state._volumeLabelTimer) {
                            clearTimeout(state._volumeLabelTimer);
                            state._volumeLabelTimer = null;
                        }
                        syncUrlPmodVolume(null);

                        // Обновляем лейбл тиража и h1 (Аспро обновит textContent,
                        // MutationObserver синхронизирует title; но также страхуемся явной установкой)
                        if (!isNaN(volXmlId)) {
                            var presetLabelStr = volXmlId.toLocaleString('ru-RU');
                            var volumeInnerEl  = innerEl;
                            var labelSpan = volumeInnerEl.querySelector('.sku-props__title .sku-props__js-size');
                            if (labelSpan) {
                                labelSpan.textContent = presetLabelStr;
                            }
                        }
                        state._pendingUiUpdate = true;
                        PModificator.registerCustomPropertyChange(state, shouldWaitForAspro);
                    }

                } else if (state.allPropIds && state.allPropIds.indexOf(parseInt(propId, 10)) !== -1) {
                    // Клик по «прочему» свойству (красочность, бумага и т.д.) — обновляем activeOtherProps
                    var otherEnumId = parseInt(btn.dataset.onevalue, 10);
                    if (!isNaN(otherEnumId)) {
                        state.activeOtherProps[parseInt(propId, 10)] = otherEnumId;
                    }
                    state._pendingUiUpdate = true;
                    PModificator.registerCustomPropertyChange(state, shouldWaitForAspro);
                }

            }, true); // capture — срабатывает до skuAction.js
        },

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

        findNearestVolumePreset: function (valuesEl, v, volumeEnumMap) {
            var best = null, bestDist = Infinity;
            valuesEl.querySelectorAll('.sku-props__value').forEach(function (btn) {
                var eid = btn.dataset.onevalue || '';
                var t = (volumeEnumMap && eid && volumeEnumMap[eid] !== undefined)
                    ? parseInt(volumeEnumMap[eid], 10)
                    : parseInt((btn.dataset.title || '').replace(/[^0-9]/g, ''), 10);
                if (isNaN(t)) return;
                var d = Math.abs(t - v);
                if (d < bestDist) { bestDist = d; best = btn; }
            });
            return best;
        }

    };
})();
