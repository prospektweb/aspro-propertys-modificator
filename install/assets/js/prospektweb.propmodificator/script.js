/**
 * prospektweb.propmodificator — фронтенд рендерер пользовательских полей.
 *
 * Роль модуля:
 *  - рендерит поля из window.pmodConfig.products[productId].customConfig.fields
 *  - хранит и публикует локальное состояние значений
 *  - применяет локальные условия показа/скрытия полей
 *
 * Модуль НЕ занимается SKU, ценами, title/h1, событиями Aspro и прочими побочными эффектами.
 */

;(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function toNumberOrNull(value) {
        if (value === '' || value === null || value === undefined) return null;
        var n = Number(value);
        return isNaN(n) ? null : n;
    }

    function clamp(value, min, max) {
        var v = toNumberOrNull(value);
        if (v === null) return value;
        if (typeof min === 'number') v = Math.max(min, v);
        if (typeof max === 'number') v = Math.min(max, v);
        return v;
    }

    function createElement(tag, className, html) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (html != null) node.innerHTML = html;
        return node;
    }

    function normalizeInput(input, idx) {
        var min = toNumberOrNull(input && input.min);
        var max = toNumberOrNull(input && input.max);
        var step = toNumberOrNull(input && input.step);
        return {
            id: 'input_' + idx,
            label: String(input && input.label || ('Значение ' + (idx + 1))),
            min: min,
            max: max,
            step: step && step > 0 ? step : 1,
            measure: String(input && input.measure || ''),
            showMeasure: !!(input && input.showMeasure)
        };
    }

    function normalizeField(field, idx) {
        var inputs = Array.isArray(field && field.inputs) ? field.inputs : [];
        if (!inputs.length) inputs = [{}];

        return {
            id: String(field && field.id || ('field_' + idx)),
            code: String(field && field.binding && field.binding.skuPropertyCode || ''),
            name: String(field && field.name || ('Поле ' + (idx + 1))),
            mode: String(field && field.mode || 'single'),
            type: String(field && field.type || 'number'),
            options: Array.isArray(field && field.options) ? field.options : [],
            conditions: Array.isArray(field && field.conditions) ? field.conditions : [],
            inputs: inputs.slice(0, 4).map(normalizeInput)
        };
    }

    function evaluateCondition(cond, values) {
        var source = String(cond && (cond.fieldId || cond.code) || '');
        if (!source) return true;

        var operator = String(cond && cond.operator || 'eq');
        var expected = cond && cond.value;
        var actual = values[source];

        if (operator === 'neq') return actual !== expected;
        if (operator === 'in' && Array.isArray(expected)) return expected.indexOf(actual) !== -1;
        if (operator === 'not_in' && Array.isArray(expected)) return expected.indexOf(actual) === -1;
        if (operator === 'gt') return Number(actual) > Number(expected);
        if (operator === 'gte') return Number(actual) >= Number(expected);
        if (operator === 'lt') return Number(actual) < Number(expected);
        if (operator === 'lte') return Number(actual) <= Number(expected);
        if (operator === 'exists') return actual !== undefined && actual !== null && actual !== '';
        return actual === expected;
    }

    var PmodFields = {
        init: function () {
            var cfg = window.pmodConfig;
            if (!cfg || !cfg.products) return;

            var containers = document.querySelectorAll('.sku-props');
            if (!containers.length) return;

            containers.forEach(function (container) {
                PmodFields.initContainer(container, cfg.products || {});
            });
        },

        initContainer: function (container, products) {
            var productId = parseInt(container.dataset.itemId, 10);
            if (!productId || !products[productId]) return;

            var productCfg = products[productId];
            var rawFields = productCfg.customConfig && productCfg.customConfig.fields;
            var fields = Array.isArray(rawFields) ? rawFields.map(normalizeField) : [];
            if (!fields.length) return;

            var root = createElement('div', 'pmod-fields-root');
            container.appendChild(root);

            var state = {
                productId: productId,
                container: container,
                root: root,
                fields: fields,
                values: {},
                fieldNodes: {},
                listeners: []
            };

            fields.forEach(function (field) {
                var fieldNode = PmodFields.renderField(field, state);
                state.fieldNodes[field.id] = fieldNode;
                root.appendChild(fieldNode);
            });

            PmodFields.applyVisibility(state);
            PmodFields.publishState(state);
            PmodFields.attachApi(state);
        },

        renderField: function (field, state) {
            var node = createElement('section', 'pmod-field');
            node.dataset.fieldId = field.id;
            if (field.code) node.dataset.fieldCode = field.code;

            var title = createElement('h4', 'pmod-field__title');
            title.textContent = field.name;
            node.appendChild(title);

            var body = createElement('div', 'pmod-field__body');
            node.appendChild(body);

            if (field.type === 'select') {
                var select = createElement('select', 'pmod-field__select');
                select.innerHTML = (field.options || []).map(function (opt) {
                    var value = String(opt && opt.value !== undefined ? opt.value : '');
                    var label = String(opt && opt.label !== undefined ? opt.label : value);
                    return '<option value="' + value.replace(/"/g, '&quot;') + '">' + label + '</option>';
                }).join('');

                var key = field.id;
                state.values[key] = select.value;
                if (field.code) state.values[field.code] = select.value;

                select.addEventListener('change', function () {
                    PmodFields.setValue(state, field, select.value);
                });
                state.listeners.push({ node: select, event: 'change' });
                body.appendChild(select);
                return node;
            }

            field.inputs.forEach(function (input, idx) {
                var group = createElement('div', 'pmod-field__input-group');
                var label = createElement('label', 'pmod-field__label');
                label.textContent = input.label;
                group.appendChild(label);

                var row = createElement('div', 'pmod-field__input-row');
                var inp = createElement('input', 'pmod-field__input');
                inp.type = 'number';
                if (input.min !== null) inp.min = String(input.min);
                if (input.max !== null) inp.max = String(input.max);
                inp.step = String(input.step);
                row.appendChild(inp);

                if (input.showMeasure && input.measure) {
                    var measure = createElement('span', 'pmod-field__measure');
                    measure.textContent = input.measure;
                    row.appendChild(measure);
                }

                inp.addEventListener('input', function () {
                    PmodFields.updateFieldValueFromInputs(state, field, node);
                });
                inp.addEventListener('blur', function () {
                    var val = clamp(inp.value, input.min, input.max);
                    if (val !== null && val !== '') inp.value = String(val);
                    PmodFields.updateFieldValueFromInputs(state, field, node);
                });

                state.listeners.push({ node: inp, event: 'input' });
                state.listeners.push({ node: inp, event: 'blur' });

                group.appendChild(row);
                body.appendChild(group);
            });

            PmodFields.updateFieldValueFromInputs(state, field, node);
            return node;
        },

        updateFieldValueFromInputs: function (state, field, fieldNode) {
            var inputs = fieldNode.querySelectorAll('.pmod-field__input');
            var values = Array.prototype.slice.call(inputs).map(function (inp) {
                return inp.value === '' ? null : toNumberOrNull(inp.value);
            });

            var value = values.length === 1 ? values[0] : values;
            PmodFields.setValue(state, field, value);
        },

        setValue: function (state, field, value) {
            state.values[field.id] = value;
            if (field.code) state.values[field.code] = value;
            PmodFields.applyVisibility(state);
            PmodFields.publishState(state);
        },

        applyVisibility: function (state) {
            state.fields.forEach(function (field) {
                var node = state.fieldNodes[field.id];
                if (!node) return;

                if (!field.conditions.length) {
                    node.style.display = '';
                    return;
                }

                var visible = field.conditions.every(function (cond) {
                    return evaluateCondition(cond, state.values);
                });

                node.style.display = visible ? '' : 'none';
            });
        },

        publishState: function (state) {
            var payload = {
                productId: state.productId,
                values: JSON.parse(JSON.stringify(state.values))
            };

            window.pmodFieldsState = window.pmodFieldsState || {};
            window.pmodFieldsState[state.productId] = payload.values;

            state.container.dispatchEvent(new CustomEvent('pmod:change', { detail: payload }));
        },

        attachApi: function (state) {
            var api = {
                getState: function () {
                    return JSON.parse(JSON.stringify(state.values));
                },
                exportConfig: function () {
                    return JSON.stringify({ version: 1, fields: state.fields }, null, 2);
                },
                importState: function (nextState) {
                    if (!nextState || typeof nextState !== 'object') return;
                    state.fields.forEach(function (field) {
                        var key = Object.prototype.hasOwnProperty.call(nextState, field.id)
                            ? field.id
                            : field.code;
                        if (!key || !Object.prototype.hasOwnProperty.call(nextState, key)) return;

                        var value = nextState[key];
                        var node = state.fieldNodes[field.id];
                        if (!node) return;

                        if (field.type === 'select') {
                            var select = node.querySelector('.pmod-field__select');
                            if (select) select.value = String(value);
                        } else {
                            var list = node.querySelectorAll('.pmod-field__input');
                            if (Array.isArray(value)) {
                                value.forEach(function (v, idx) {
                                    if (list[idx]) list[idx].value = v == null ? '' : String(v);
                                });
                            } else if (list[0]) {
                                list[0].value = value == null ? '' : String(value);
                            }
                        }

                        PmodFields.setValue(state, field, value);
                    });
                }
            };

            state.container.pmodFieldsApi = api;
        }
    };

    ready(function () {
        PmodFields.init();
    });
})();
