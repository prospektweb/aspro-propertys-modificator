;(function () {
    'use strict';

    var cfg = window.pmodAdminConfig;
    if (!cfg || !cfg.customConfigPropertyId) return;

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function q(sel, root) { return (root || document).querySelector(sel); }
    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    }

    function getTextarea() {
        return q('textarea[name^="PROP[' + cfg.customConfigPropertyId + ']"], textarea[name*="PROP[' + cfg.customConfigPropertyId + ']"]');
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
            label: input && input.label ? input.label : '',
            min: input && input.min !== undefined ? input.min : '',
            step: input && input.step !== undefined ? input.step : '',
            max: input && input.max !== undefined ? input.max : '',
            measure: input && input.measure ? input.measure : '',
            showMeasure: !!(input && input.showMeasure),
            hidePresetButtons: !!(input && input.hidePresetButtons)
        };
    }

    function fetchMeta(cb) {
        var fd = new FormData();
        fd.append('action', 'meta');
        fd.append('sessid', cfg.sessid);

        fetch(cfg.apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) { cb(json && json.success ? json.properties : []); })
            .catch(function () { cb([]); });
    }

    function saveJson(textarea, state) {
        textarea.value = JSON.stringify({ version: 1, fields: state.fields }, null, 2);
    }

    function validateField(field) {
        if (!field.binding || !field.binding.skuPropertyCode) return 'Не выбрано свойство ТП';
        if (!field.inputs || !field.inputs.length) return 'Добавьте хотя бы один инпут';
        if (field.mode === 'single' && field.inputs.length !== 1) return 'Single-поле должно иметь 1 инпут';
        if (field.mode === 'group' && (field.inputs.length < 1 || field.inputs.length > 4)) return 'Группа: от 1 до 4 инпутов';

        for (var i = 0; i < field.inputs.length; i++) {
            var inp = field.inputs[i];
            var min = inp.min === '' ? null : Number(inp.min);
            var max = inp.max === '' ? null : Number(inp.max);
            var step = inp.step === '' ? null : Number(inp.step);
            if (min !== null && max !== null && min > max) return 'MIN не может быть больше MAX';
            if (step !== null && step <= 0) return 'STEP должен быть > 0';
        }

        return '';
    }

    function createField(properties) {
        var mode = window.prompt('Тип поля: single или group', 'single');
        if (!mode || (mode !== 'single' && mode !== 'group')) return null;

        var bindCode = window.prompt('Код свойства ТП (list) для привязки', mode === 'single' ? cfg.volumePropCode : cfg.formatPropCode);
        if (!bindCode) return null;

        var bindProp = properties.find(function (p) { return p.code === bindCode; });
        if (!bindProp) {
            alert('Свойство не найдено в списке list-свойств ТП');
            return null;
        }

        var markerXml = window.prompt('XML_ID маркера произвольного значения', 'CUSTOM_' + bindCode);
        var markerVal = window.prompt('VALUE маркера произвольного значения', 'Произвольное значение');
        if (!markerXml || !markerVal) return null;

        var groupSize = mode === 'group' ? Number(window.prompt('Сколько инпутов в группе (1-4)', '2')) : 1;
        if (mode === 'group' && (groupSize < 1 || groupSize > 4)) return null;

        var inputs = [];
        for (var i = 0; i < groupSize; i++) {
            inputs.push(normalizeInput({ label: mode === 'group' ? ('Поле ' + (i + 1)) : 'Значение' }));
        }

        return {
            id: 'f_' + Date.now() + '_' + Math.round(Math.random() * 1000),
            name: bindProp.name,
            mode: mode,
            binding: {
                skuPropertyId: bindProp.id,
                skuPropertyCode: bindProp.code,
                marker: { xmlId: markerXml, value: markerVal }
            },
            replaceKeys: inputs.map(function (_, idx) { return { key: '', inputIndex: idx }; }),
            inputs: inputs
        };
    }

    function createMarker(field, done) {
        var fd = new FormData();
        fd.append('action', 'create_marker');
        fd.append('sessid', cfg.sessid);
        fd.append('property_id', String(field.binding.skuPropertyId));
        fd.append('xml_id', field.binding.marker.xmlId || '');
        fd.append('value', field.binding.marker.value || '');

        fetch(cfg.apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json.success) {
                    alert('Не удалось создать маркер: ' + (json.error || 'unknown'));
                } else {
                    alert('Маркер создан');
                }
                done();
            })
            .catch(function () {
                alert('Ошибка запроса создания маркера');
                done();
            });
    }

    function renderEditor(root, textarea, state, properties) {
        root.innerHTML = '';

        var head = el('div', 'pmod-admin-head', '<h3>Конструктор произвольных полей</h3><p>MIN | STEP | MAX | MEASURE | чекбоксы | REPLACE_KEY</p>');
        var addBtn = el('button', 'adm-btn pmod-admin-btn', 'Добавить поле');
        addBtn.type = 'button';
        addBtn.onclick = function () {
            var field = createField(properties);
            if (!field) return;
            state.fields.push(field);
            saveJson(textarea, state);
            renderEditor(root, textarea, state, properties);
        };
        head.appendChild(addBtn);
        root.appendChild(head);

        state.fields.forEach(function (field, fieldIdx) {
            var card = el('div', 'pmod-admin-card');
            var err = validateField(field);
            card.appendChild(el('div', 'pmod-admin-card__title', (field.name || 'Поле') + ' <small>(' + field.mode + ')</small>' + (err ? ' <span class="pmod-admin-err">' + err + '</span>' : '')));

            var binding = el('div', 'pmod-admin-binding');
            binding.innerHTML = '<div><b>Привязка:</b> ' + (field.binding.skuPropertyCode || '-') + '</div>' +
                '<div class="pmod-admin-marker"><input type="text" placeholder="XML_ID" value="' + (field.binding.marker && field.binding.marker.xmlId ? field.binding.marker.xmlId : '') + '"> <input type="text" placeholder="VALUE" value="' + (field.binding.marker && field.binding.marker.value ? field.binding.marker.value : '') + '"> <button type="button" class="adm-btn">Создать маркер</button></div>';

            var markerInputs = binding.querySelectorAll('input');
            markerInputs[0].oninput = function () { field.binding.marker.xmlId = this.value; saveJson(textarea, state); };
            markerInputs[1].oninput = function () { field.binding.marker.value = this.value; saveJson(textarea, state); };
            binding.querySelector('button').onclick = function () { createMarker(field, function () {}); };
            card.appendChild(binding);

            field.inputs.forEach(function (input, inputIdx) {
                var row = el('div', 'pmod-admin-row');
                row.innerHTML =
                    '<input class="pmod-inp" placeholder="MIN" value="' + (input.min === '' ? '' : input.min) + '">' +
                    '<input class="pmod-inp" placeholder="STEP" value="' + (input.step === '' ? '' : input.step) + '">' +
                    '<input class="pmod-inp" placeholder="MAX" value="' + (input.max === '' ? '' : input.max) + '">' +
                    '<input class="pmod-inp pmod-measure" placeholder="MEASURE" value="' + (input.measure || '') + '">' +
                    '<label><input type="checkbox" ' + (input.showMeasure ? 'checked' : '') + '> Показывать ед.</label>' +
                    '<label><input type="checkbox" ' + (input.hidePresetButtons ? 'checked' : '') + '> Скрывать пресеты</label>' +
                    '<input class="pmod-inp pmod-replace" placeholder="REPLACE_KEY" value="' + ((field.replaceKeys[inputIdx] && field.replaceKeys[inputIdx].key) || '') + '">';

                var controls = row.querySelectorAll('input');
                controls[0].oninput = function () { input.min = this.value; saveJson(textarea, state); };
                controls[1].oninput = function () { input.step = this.value; saveJson(textarea, state); };
                controls[2].oninput = function () { input.max = this.value; saveJson(textarea, state); };
                controls[3].oninput = function () { input.measure = this.value; saveJson(textarea, state); };
                controls[4].onchange = function () { input.showMeasure = this.checked; saveJson(textarea, state); };
                controls[5].onchange = function () { input.hidePresetButtons = this.checked; saveJson(textarea, state); };
                controls[6].oninput = function () {
                    field.replaceKeys[inputIdx] = field.replaceKeys[inputIdx] || { key: '', inputIndex: inputIdx };
                    field.replaceKeys[inputIdx].key = this.value;
                    saveJson(textarea, state);
                };

                card.appendChild(row);
            });

            var actions = el('div', 'pmod-admin-actions');
            var addInput = el('button', 'adm-btn', '+ Инпут');
            addInput.type = 'button';
            addInput.onclick = function () {
                if (field.mode !== 'group' || field.inputs.length >= 4) return;
                field.inputs.push(normalizeInput({}));
                field.replaceKeys.push({ key: '', inputIndex: field.inputs.length - 1 });
                saveJson(textarea, state);
                renderEditor(root, textarea, state, properties);
            };

            var removeField = el('button', 'adm-btn', 'Удалить поле');
            removeField.type = 'button';
            removeField.onclick = function () {
                state.fields.splice(fieldIdx, 1);
                saveJson(textarea, state);
                renderEditor(root, textarea, state, properties);
            };

            actions.appendChild(addInput);
            actions.appendChild(removeField);
            card.appendChild(actions);

            root.appendChild(card);
        });
    }

    ready(function () {
        var textarea = getTextarea();
        if (!textarea) return;

        textarea.style.display = 'none';
        var root = el('div', 'pmod-admin-root');
        textarea.parentNode.insertBefore(root, textarea);

        fetchMeta(function (properties) {
            var state = parseJsonSafe(textarea.value);
            renderEditor(root, textarea, state, properties || []);
            saveJson(textarea, state);
        });
    });
})();
