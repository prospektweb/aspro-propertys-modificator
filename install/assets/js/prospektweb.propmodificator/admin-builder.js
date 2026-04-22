;(function () {
    'use strict';

    var cfg = null;

    function resolveCfg() {
        var c = window.pmodAdminConfig || null;
        if (c && c.customConfigPropertyId) return c;
        return null;
    }

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function q(sel, root) { return (root || document).querySelector(sel); }
    function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function logWarn(msg, extra) {
        if (extra !== undefined) {
            console.warn('pmod admin builder:', msg, extra);
        } else {
            console.warn('pmod admin builder:', msg);
        }
    }
    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function collectTextareaCandidates() {
        var pid = String(cfg.customConfigPropertyId);
        var pcode = String(cfg.customConfigPropertyCode || '').toUpperCase();
        var all = qa('textarea');
        var scoreMap = [];

        all.forEach(function (node) {
            var name = String(node.getAttribute('name') || '');
            var id = String(node.getAttribute('id') || '');
            var nm = name.toUpperCase();
            var im = id.toUpperCase();
            var score = 0;

            if (name.indexOf('PROP[' + pid + ']') !== -1) score += 50;
            if (name.indexOf('PROP_' + pid) !== -1) score += 40;
            if (id.indexOf('PROP_' + pid) !== -1) score += 30;
            if (id.indexOf(pid + '_VALUE_TEXT') !== -1) score += 35;
            if (id.indexOf('_PROP_' + pid + '_') !== -1) score += 42;
            if (name.indexOf('PROP[') !== -1 && name.indexOf('][VALUE][TEXT]') !== -1 && name.indexOf('[' + pid + ']') !== -1) score += 45;
            if (name.indexOf('PROP[') !== -1 && name.indexOf('][~VALUE][TEXT]') !== -1 && name.indexOf('[' + pid + ']') !== -1) score += 45;
            if (pcode && (nm.indexOf(pcode) !== -1 || im.indexOf(pcode) !== -1)) score += 15;

            if (score > 0) {
                scoreMap.push({ node: node, score: score });
            }
        });

        scoreMap.sort(function (a, b) { return b.score - a.score; });
        return scoreMap.map(function (x) { return x.node; });
    }

    function getTextarea() {
        var found = collectTextareaCandidates();
        if (!found.length && cfg.customConfigPropertyCode) {
            var lbl = qa('label, b, td, span').find(function (n) {
                return String(n.textContent || '').toUpperCase().indexOf(String(cfg.customConfigPropertyCode).toUpperCase()) !== -1;
            });
            if (lbl) {
                var ctx = lbl.closest('tr') || lbl.closest('.adm-detail-content-cell-r') || lbl.parentNode;
                if (ctx) {
                    var near = q('textarea', ctx);
                    if (near) return near;
                }
            }
        }
        return found.length ? found[0] : null;
    }

    function parseJsonSafe(raw) {
        if (!raw) return { version: 1, fields: [] };
        try {
            var parsed = JSON.parse(raw);
            if (!parsed || !Array.isArray(parsed.fields)) return { version: 1, fields: [] };
            return parsed;
        } catch (e) {
            return { version: 1, fields: [] };
        }
    }


    function normalizeInput(input) {
        return {
            label: input && input.label ? String(input.label) : '',
            min: input && input.min !== undefined ? input.min : '',
            step: input && input.step !== undefined ? input.step : '',
            max: input && input.max !== undefined ? input.max : '',
            measure: input && input.measure ? String(input.measure) : '',
            showMeasure: !!(input && input.showMeasure),
            hidePresetButtons: !!(input && input.hidePresetButtons)
        };
    }

    function normalizeField(field) {
        var inputs = Array.isArray(field && field.inputs) ? field.inputs.map(normalizeInput) : [normalizeInput({})];
        if (inputs.length > 4) {
            inputs = inputs.slice(0, 4);
        } else if (inputs.length === 0) {
            inputs = [normalizeInput({})];
        }
        var mode = inputs.length > 1 ? 'group' : 'single';
        var rawInputLabels = Array.isArray(field && field.inputLabels) ? field.inputLabels : [];
        var normalizedInputLabels = inputs.map(function (_, idx) {
            var label = rawInputLabels[idx];
            return label != null ? String(label) : '';
        });

        var replaceKeys = Array.isArray(field && field.replaceKeys) ? field.replaceKeys : [];
        var normalizedReplace = inputs.map(function (_, idx) {
            var rk = replaceKeys[idx] || {};
            return {
                key: rk.key ? String(rk.key) : '',
                inputIndex: idx
            };
        });

        return {
            id: (field && field.id) ? String(field.id) : ('f_' + Date.now() + '_' + Math.round(Math.random() * 100000)),
            name: (field && field.name) ? String(field.name) : '',
            mode: mode,
            binding: {
                skuPropertyId: Number(field && field.binding && field.binding.skuPropertyId ? field.binding.skuPropertyId : 0) || 0,
                skuPropertyCode: (field && field.binding && field.binding.skuPropertyCode) ? String(field.binding.skuPropertyCode) : '',
                marker: {
                    xmlId: (field && field.binding && field.binding.marker && field.binding.marker.xmlId) ? String(field.binding.marker.xmlId) : '',
                    value: (field && field.binding && field.binding.marker && field.binding.marker.value) ? String(field.binding.marker.value) : ''
                }
            },
            replaceKeys: normalizedReplace,
            inputs: inputs,
            inputLabels: normalizedInputLabels
        };
    }

    function syncFieldMode(field) {
        if (!field || !Array.isArray(field.inputs)) return;
        field.mode = field.inputs.length > 1 ? 'group' : 'single';
    }

    function normalizeState(rawState) {
        var state = {
            version: 1,
            fields: []
        };
        if (!rawState || !Array.isArray(rawState.fields)) return state;

        state.fields = rawState.fields.filter(function (f) { return f && typeof f === 'object'; }).map(normalizeField);
        return state;
    }

    function saveJson(textarea, state) {
        textarea.value = JSON.stringify({ version: 1, fields: state.fields }, null, 2);
        try {
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) {
            var evt = document.createEvent('HTMLEvents');
            evt.initEvent('change', true, false);
            textarea.dispatchEvent(evt);
        }
    }

    function validateField(field) {
        if (!field.binding || !field.binding.skuPropertyCode) return 'Выберите list-свойство ТП для привязки';
        if (!field.binding.marker || !field.binding.marker.xmlId || !field.binding.marker.value) return 'Заполните XML_ID и VALUE маркера';
        if (!field.inputs || !field.inputs.length) return 'Добавьте хотя бы один инпут';
        if (field.mode === 'single' && field.inputs.length !== 1) return 'Режим single должен иметь 1 инпут';
        if (field.mode === 'group' && (field.inputs.length < 1 || field.inputs.length > 4)) return 'Режим group: от 1 до 4 инпутов';

        for (var i = 0; i < field.inputs.length; i++) {
            var inp = field.inputs[i] || {};
            var min = inp.min === '' ? null : Number(inp.min);
            var max = inp.max === '' ? null : Number(inp.max);
            var step = inp.step === '' ? null : Number(inp.step);
            if (min !== null && isNaN(min)) return 'MIN должен быть числом';
            if (max !== null && isNaN(max)) return 'MAX должен быть числом';
            if (step !== null && isNaN(step)) return 'STEP должен быть числом';
            if (min !== null && max !== null && min > max) return 'MIN не может быть больше MAX';
            if (step !== null && step <= 0) return 'STEP должен быть > 0';
        }

        return '';
    }

    function renderPropertyOptions(props, selectedCode) {
        return props.map(function (p) {
            var sel = p.code === selectedCode ? ' selected' : '';
            return '<option value="' + escapeHtml(p.code) + '" data-id="' + p.id + '"' + sel + '>' + escapeHtml(p.name + ' (' + p.code + ')') + '</option>';
        }).join('');
    }

    function fetchMeta(cb) {
        var fd = new FormData();
        fd.append('action', 'meta');
        fd.append('sessid', cfg.sessid);

        fetch(cfg.apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) { cb((json && json.success && Array.isArray(json.properties)) ? json.properties : []); })
            .catch(function () { cb([]); });
    }

    function setStatus(root, message, type) {
        var box = q('[data-role="status"]', root);
        if (!box) return;
        box.className = 'pmod-admin-status pmod-admin-status--' + (type || 'info');
        box.textContent = message || '';
    }

    function createMarker(field, done) {
        var fd = new FormData();
        fd.append('action', 'create_marker');
        fd.append('sessid', cfg.sessid);
        fd.append('property_id', String(field.binding.skuPropertyId || 0));
        fd.append('xml_id', String(field.binding.marker.xmlId || ''));
        fd.append('value', String(field.binding.marker.value || ''));

        fetch(cfg.apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    done(false, 'Не удалось создать/обновить маркер: ' + ((json && json.error) || 'unknown'));
                } else {
                    done(true, 'Маркер готов: #' + json.enum_id + (json.updated ? ' (обновлён)' : ' (создан)'));
                }
            })
            .catch(function () {
                done(false, 'Ошибка запроса к admin_config.php');
            });
    }

    function makeNewField(propCode, props) {
        var prop = null;
        for (var i = 0; i < props.length; i++) {
            if (props[i].code === propCode) {
                prop = props[i];
                break;
            }
        }

        var inputs = [normalizeInput({ label: 'Значение' })];

        return normalizeField({
            id: 'f_' + Date.now() + '_' + Math.round(Math.random() * 1000),
            name: prop ? prop.name : '',
            mode: 'single',
            binding: {
                skuPropertyId: prop ? Number(prop.id) : 0,
                skuPropertyCode: prop ? prop.code : '',
                marker: {
                    xmlId: prop ? ('CUSTOM_' + prop.code) : '',
                    value: 'Произвольное значение'
                }
            },
            replaceKeys: inputs.map(function (_, idx) { return { key: '', inputIndex: idx }; }),
            inputs: inputs
        });
    }

    function renderHeader(root, textarea, state, props, rerender) {
        var head = el('div', 'pmod-admin-head');
        head.innerHTML =
            '<div class="pmod-admin-head__text">'
            + '<h3>Редактор полей для произвольных значений</h3>'
            + '<div class="pmod-admin-status pmod-admin-status--info" data-role="status"></div>'
            + '</div>'
            + '<div class="pmod-admin-head__controls">'
            + '<select class="pmod-inp" data-role="new-bind">' + renderPropertyOptions(props, cfg.volumePropCode || '') + '</select>'
            + '<button type="button" class="adm-btn adm-btn-save" data-role="add-field">Добавить поле</button>'
            + '</div>';

        head.querySelector('[data-role="add-field"]').onclick = function () {
            if (!props.length) {
                setStatus(root, 'Нет list-свойств в инфоблоке ТП. Проверьте настройки инфоблока предложений.', 'error');
                return;
            }
            var bindCode = head.querySelector('[data-role="new-bind"]').value;
            state.fields.push(makeNewField(bindCode, props));
            saveJson(textarea, state);
            rerender();
        };

        root.appendChild(head);
    }

    function bindRowEvents(row, field, input, inputIdx, textarea, state) {
        row.querySelector('[data-k="min"]').oninput = function () { input.min = this.value; saveJson(textarea, state); };
        row.querySelector('[data-k="step"]').oninput = function () { input.step = this.value; saveJson(textarea, state); };
        row.querySelector('[data-k="max"]').oninput = function () { input.max = this.value; saveJson(textarea, state); };
        row.querySelector('[data-k="measure"]').oninput = function () { input.measure = this.value; saveJson(textarea, state); };
        row.querySelector('[data-k="show"]').onchange = function () { input.showMeasure = !!this.checked; saveJson(textarea, state); };
        row.querySelector('[data-k="hide"]').onchange = function () { input.hidePresetButtons = !!this.checked; saveJson(textarea, state); };
        row.querySelector('[data-k="replace"]').oninput = function () {
            field.replaceKeys[inputIdx] = field.replaceKeys[inputIdx] || { key: '', inputIndex: inputIdx };
            field.replaceKeys[inputIdx].key = this.value;
            saveJson(textarea, state);
        };
    }

    function bindInputLabelEvents(root, field, textarea, state) {
        qa('[data-k="input-label"]', root).forEach(function (node) {
            node.oninput = function () {
                var idx = Number(this.getAttribute('data-idx') || 0) || 0;
                field.inputLabels[idx] = this.value;
                saveJson(textarea, state);
            };
        });
    }

    function renderEditor(root, textarea, rawState, props) {
        var state = normalizeState(rawState);
        root.innerHTML = '';

        function rerender() {
            renderEditor(root, textarea, state, props);
        }

        renderHeader(root, textarea, state, props, rerender);

        if (!state.fields.length) {
            root.appendChild(el('div', 'pmod-admin-empty', 'Пока нет полей. Добавьте первое поле сверху.'));
        }

        state.fields.forEach(function (field, fieldIdx) {
            var card = el('div', 'pmod-admin-card');
            var err = validateField(field);
            var body = el('div', 'pmod-admin-card__body');
            var collapsed = true;

            card.appendChild(el(
                'div',
                'pmod-admin-card__title',
                escapeHtml(field.name || 'Поле без названия')
                + ' <small>(' + escapeHtml(field.mode) + ')</small>'
                + (err ? ' <span class="pmod-admin-err">' + escapeHtml(err) + '</span>' : '')
                + ' <button type="button" class="adm-btn pmod-admin-card__toggle" data-k="toggle-body">Настроить</button>'
            ));

            var binding = el('div', 'pmod-admin-binding');
            var propOptions = renderPropertyOptions(props, field.binding.skuPropertyCode || '');
            binding.innerHTML =
                '<div class="pmod-admin-binding__grid">'
                + '<label>Название поля<input class="pmod-inp" data-k="name" value="' + escapeHtml(field.name || '') + '"></label>'
                + '<label>Привязка к свойству ТП<select class="pmod-inp" data-k="prop">' + propOptions + '</select></label>'
                + '<label>XML_ID маркера<input class="pmod-inp" data-k="xml" value="' + escapeHtml(field.binding.marker.xmlId || '') + '"></label>'
                + '<label>VALUE маркера<input class="pmod-inp" data-k="val" value="' + escapeHtml(field.binding.marker.value || '') + '"></label>'
                + '</div>'
                + '<div class="pmod-admin-actions">'
                + '<button type="button" class="adm-btn" data-k="create-marker">Создать / обновить маркер</button>'
                + '</div>';

            binding.querySelector('[data-k="name"]').oninput = function () { field.name = this.value; saveJson(textarea, state); };
            binding.querySelector('[data-k="xml"]').oninput = function () { field.binding.marker.xmlId = this.value; saveJson(textarea, state); };
            binding.querySelector('[data-k="val"]').oninput = function () { field.binding.marker.value = this.value; saveJson(textarea, state); };
            binding.querySelector('[data-k="prop"]').onchange = function () {
                var selected = props.filter(function (p) { return p.code === this.value; }.bind(this))[0] || null;
                field.binding.skuPropertyCode = selected ? selected.code : '';
                field.binding.skuPropertyId = selected ? Number(selected.id) : 0;
                if (!field.name && selected) field.name = selected.name;
                saveJson(textarea, state);
                rerender();
            };
            binding.querySelector('[data-k="create-marker"]').onclick = function () {
                createMarker(field, function (ok, msg) {
                    setStatus(root, msg, ok ? 'success' : 'error');
                    saveJson(textarea, state);
                });
            };
            body.appendChild(binding);

            if (field.inputs.length > 1) {
                var labelsBox = el('div', 'pmod-admin-binding');
                var labelsGrid = '<div class="pmod-admin-binding__grid">';
                field.inputs.forEach(function (_, inputIdx) {
                    labelsGrid += '<label>Лейбл инпута ' + (inputIdx + 1)
                        + '<input class="pmod-inp" data-k="input-label" data-idx="' + inputIdx + '" value="'
                        + escapeHtml((field.inputLabels && field.inputLabels[inputIdx]) || '') + '"></label>';
                });
                labelsGrid += '</div>';
                labelsBox.innerHTML = labelsGrid;
                bindInputLabelEvents(labelsBox, field, textarea, state);
                body.appendChild(labelsBox);
            }

            field.inputs.forEach(function (input, inputIdx) {
                var row = el('div', 'pmod-admin-row');
                row.innerHTML =
                    '<input class="pmod-inp" data-k="min" placeholder="Минимум" value="' + escapeHtml(input.min) + '">'
                    + '<input class="pmod-inp" data-k="step" placeholder="Шаг" value="' + escapeHtml(input.step) + '">'
                    + '<input class="pmod-inp" data-k="max" placeholder="Максимум" value="' + escapeHtml(input.max) + '">'
                    + '<input class="pmod-inp" data-k="measure" placeholder="Ед. изм." value="' + escapeHtml(input.measure || '') + '">'
                    + '<label class="pmod-check"><input type="checkbox" data-k="show" ' + (input.showMeasure ? 'checked' : '') + '>Показывать ед. изм.</label>'
                    + '<label class="pmod-check"><input type="checkbox" data-k="hide" ' + (input.hidePresetButtons ? 'checked' : '') + '>Скрывать варианты</label>'
                    + '<input class="pmod-inp" data-k="replace" title="Ключ, по которому подставляется пользовательское значение в шаблон/замену." placeholder="Подстановка по ключу" value="' + escapeHtml((field.replaceKeys[inputIdx] && field.replaceKeys[inputIdx].key) || '') + '">';

                bindRowEvents(row, field, input, inputIdx, textarea, state);
                body.appendChild(row);
            });

            var actions = el('div', 'pmod-admin-actions');
            actions.innerHTML =
                '<button type="button" class="adm-btn" data-k="add-input">+ Инпут</button>'
                + '<button type="button" class="adm-btn" data-k="remove-input">− Инпут</button>'
                + '<button type="button" class="adm-btn adm-btn-delete" data-k="remove-field">Удалить поле</button>';

            actions.querySelector('[data-k="add-input"]').onclick = function () {
                if (field.inputs.length >= 4) {
                    setStatus(root, 'В группе максимум 4 инпута.', 'error');
                    return;
                }
                field.inputs.push(normalizeInput({ label: 'Поле ' + (field.inputs.length + 1) }));
                field.replaceKeys.push({ key: '', inputIndex: field.inputs.length - 1 });
                field.inputLabels.push('');
                syncFieldMode(field);
                saveJson(textarea, state);
                rerender();
            };

            actions.querySelector('[data-k="remove-input"]').onclick = function () {
                if (field.inputs.length <= 1) return;
                field.inputs.pop();
                field.replaceKeys.pop();
                field.inputLabels.pop();
                syncFieldMode(field);
                saveJson(textarea, state);
                rerender();
            };

            actions.querySelector('[data-k="remove-field"]').onclick = function () {
                state.fields.splice(fieldIdx, 1);
                saveJson(textarea, state);
                rerender();
            };

            body.appendChild(actions);
            body.style.display = collapsed ? 'none' : '';
            card.querySelector('[data-k="toggle-body"]').onclick = function () {
                collapsed = !collapsed;
                body.style.display = collapsed ? 'none' : '';
                this.textContent = collapsed ? 'Настроить' : 'Свернуть';
            };
            card.appendChild(body);
            root.appendChild(card);
        });

        saveJson(textarea, state);
    }

    function findPropertyCell(textarea) {
        var pid = String(cfg.customConfigPropertyId || '');
        var byId = q('#tr_PROPERTY_' + pid + ' .adm-detail-content-cell-r');
        if (byId) return byId;

        var row = textarea.closest('tr[id^="tr_PROPERTY_"]');
        if (row) {
            var c = q('.adm-detail-content-cell-r', row) || q('td:last-child', row);
            if (c) return c;
        }

        return textarea.closest('.adm-detail-content-cell-r')
            || textarea.closest('td')
            || textarea.parentNode;
    }

    function hideSourceEditor(cell, textarea) {
        if (!cell) return;
        var pid = String(cfg.customConfigPropertyId || '');
        var selector = 'table.nopadding, .bx-ed-type-selector, .bx-html-editor, textarea[name*="PROP_' + pid + '"], textarea[id*="PROP_' + pid + '"]';
        var blocks = qa(selector, cell);
        blocks.forEach(function (node) {
            if (node && node.classList && node.classList.contains('pmod-admin-root')) return;
            node.style.display = 'none';
        });
        if (textarea) {
            textarea.style.display = 'none';
        }
    }

    function findPropertyRow(textarea) {
        var pid = String(cfg.customConfigPropertyId || '');
        var row = q('#tr_PROPERTY_' + pid);
        if (row) return row;
        return textarea.closest('tr');
    }

    function mountBuilder() {
        var textarea = getTextarea();
        if (!textarea || !textarea.parentNode) return false;
        if (textarea._pmodBuilderMounted && textarea._pmodBuilderRoot && document.contains(textarea._pmodBuilderRoot)) {
            return true;
        }
        textarea._pmodBuilderMounted = false;
        textarea._pmodBuilderRoot = null;

        var mountHost = findPropertyCell(textarea);
        if (!mountHost) return false;
        var propertyRow = findPropertyRow(textarea);

        var root = el('div', 'pmod-admin-root');
        var hostRow = null;
        if (propertyRow && propertyRow.parentNode && !q('.pmod-admin-host-row', propertyRow.parentNode)) {
            hostRow = document.createElement('tr');
            hostRow.className = 'pmod-admin-host-row';
            var hostCell = document.createElement('td');
            hostCell.colSpan = 2;
            hostCell.className = 'adm-detail-content-cell-r';
            hostCell.appendChild(root);
            hostRow.appendChild(hostCell);
            propertyRow.parentNode.insertBefore(hostRow, propertyRow.nextSibling);
            propertyRow.style.display = 'none';
        } else if (propertyRow && propertyRow.parentNode) {
            var existedHost = q('.pmod-admin-host-row .adm-detail-content-cell-r', propertyRow.parentNode);
            if (existedHost) {
                existedHost.appendChild(root);
            } else {
                mountHost.insertBefore(root, mountHost.firstChild || null);
            }
            propertyRow.style.display = 'none';
        } else {
            mountHost.insertBefore(root, mountHost.firstChild || null);
        }

        hideSourceEditor(mountHost, textarea);
        textarea._pmodBuilderMounted = true;
        textarea._pmodBuilderRoot = root;

        fetchMeta(function (properties) {
            var state = normalizeState(parseJsonSafe(textarea.value));
            renderEditor(root, textarea, state, properties || []);
        });

        return true;
    }

    function bootstrapWithRetry() {
        var maxAttempts = 25;
        var attempts = 0;
        var observer = null;
        var timer = null;

        function stopObserver() {
            if (observer) {
                observer.disconnect();
                observer = null;
            }
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        function tryMount() {
            try {
                attempts += 1;
                cfg = resolveCfg();
                if (!cfg) {
                    if (attempts >= maxAttempts) {
                        stopObserver();
                        logWarn('window.pmodAdminConfig not ready');
                    }
                    return;
                }
                if (mountBuilder()) {
                    stopObserver();
                    return;
                }
            } catch (e) {
                logWarn('mount failed with exception', e);
            }
            if (attempts >= maxAttempts) {
                stopObserver();
                logWarn('textarea not found for property #' + cfg.customConfigPropertyId + ' code=' + cfg.customConfigPropertyCode);
            }
        }

        tryMount();
        timer = setInterval(function () {
            if (attempts >= maxAttempts) {
                clearInterval(timer);
                return;
            }
            tryMount();
            if (attempts >= maxAttempts) {
                clearInterval(timer);
            }
        }, 300);

        if (window.MutationObserver) {
            observer = new MutationObserver(function () {
                if (attempts >= maxAttempts) return;
                tryMount();
            });
            observer.observe(document.documentElement || document.body, { childList: true, subtree: true });
        }
    }

    ready(bootstrapWithRetry);
})();
