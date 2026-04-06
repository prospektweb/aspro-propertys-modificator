/**
 * prospektweb.propmodificator — UI-конструктор пользовательских полей.
 *
 * Модуль НЕ содержит бизнес-логики SKU/цены/title/Aspro.
 * Его задача:
 *  - рендерить кастомные поля в блоке .sku-props;
 *  - применять локальные условия отображения show/hide;
 *  - хранить и отдавать текущее состояние пользовательских значений.
 */
;(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function clamp(v, min, max) {
        if (typeof min === 'number' && !isNaN(min) && v < min) return min;
        if (typeof max === 'number' && !isNaN(max) && v > max) return max;
        return v;
    }

    function toNumberOrNull(v) {
        if (v === '' || v === null || v === undefined) return null;
        var n = Number(v);
        return isNaN(n) ? null : n;
    }

    function toStepOrDefault(v, fallback) {
        var n = Number(v);
        return isNaN(n) || n <= 0 ? fallback : n;
    }

    function deepClone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function normalizeInput(input, idx) {
        var min = toNumberOrNull(input && input.min);
        var max = toNumberOrNull(input && input.max);
        var step = toStepOrDefault(input && input.step, 1);
        var label = (input && input.label) ? String(input.label) : ('Значение ' + (idx + 1));

        return {
            key: 'input_' + idx,
            label: label,
            min: min,
            max: max,
            step: step,
            measure: (input && input.measure) ? String(input.measure) : '',
            showMeasure: !!(input && input.showMeasure)
        };
    }

    function normalizeField(field, idx) {
        var inputs = Array.isArray(field && field.inputs) ? field.inputs.map(normalizeInput) : [];
        if (!inputs.length) {
            inputs = [normalizeInput({}, 0)];
        }

        return {
            id: (field && field.id) ? String(field.id) : ('field_' + idx),
            name: (field && field.name) ? String(field.name) : ('Поле ' + (idx + 1)),
            binding: {
                skuPropertyId: Number(field && field.binding && field.binding.skuPropertyId ? field.binding.skuPropertyId : 0) || 0,
                skuPropertyCode: (field && field.binding && field.binding.skuPropertyCode) ? String(field.binding.skuPropertyCode) : '',
                marker: {
                    xmlId: (field && field.binding && field.binding.marker && field.binding.marker.xmlId) ? String(field.binding.marker.xmlId) : '',
                    value: (field && field.binding && field.binding.marker && field.binding.marker.value) ? String(field.binding.marker.value) : ''
                }
            },
            inputs: inputs,
            replaceKeys: Array.isArray(field && field.replaceKeys) ? field.replaceKeys : []
        };
    }

    function normalizeCustomConfig(raw) {
        var fields = Array.isArray(raw && raw.fields) ? raw.fields : [];
        return {
            version: 1,
            fields: fields.map(normalizeField)
        };
    }

    function createNumberControl(inputCfg, initialValue, onChange) {
        var wrap = document.createElement('div');
        wrap.className = 'pmod-input-group';

        var label = document.createElement('label');
        label.className = 'pmod-label';
        label.textContent = inputCfg.label + (inputCfg.showMeasure && inputCfg.measure ? ', ' + inputCfg.measure : '');

        var counter = document.createElement('div');
        counter.className = 'pmod-counter';

        var minus = document.createElement('button');
        minus.type = 'button';
        minus.className = 'pmod-counter__btn pmod-counter__minus';
        minus.setAttribute('aria-label', 'Уменьшить');
        minus.textContent = '−';

        var input = document.createElement('input');
        input.type = 'number';
        input.className = 'pmod-counter__input';
        input.autocomplete = 'off';
        input.step = String(inputCfg.step);
        if (inputCfg.min !== null) input.min = String(inputCfg.min);
        if (inputCfg.max !== null) input.max = String(inputCfg.max);
        if (initialValue !== null && initialValue !== undefined) {
            input.value = String(initialValue);
        }

        var plus = document.createElement('button');
        plus.type = 'button';
        plus.className = 'pmod-counter__btn pmod-counter__plus';
        plus.setAttribute('aria-label', 'Увеличить');
        plus.textContent = '+';

        function normalize(raw, snapToStep) {
            var min = inputCfg.min;
            var max = inputCfg.max;
            var step = inputCfg.step;

            var num = Number(raw);
            if (isNaN(num)) {
                num = min !== null ? min : 0;
            }
            num = clamp(num, min, max);
            if (snapToStep) {
                num = Math.round(num / step) * step;
                num = clamp(num, min, max);
            }
            return num;
        }

        function push(isFinal) {
            var value = normalize(input.value, !!isFinal);
            if (isFinal) {
                input.value = String(value);
            }
            onChange(value);
        }

        minus.addEventListener('click', function () {
            var cur = normalize(input.value, false);
            var next = clamp(cur - inputCfg.step, inputCfg.min, inputCfg.max);
            input.value = String(next);
            onChange(next);
        });

        plus.addEventListener('click', function () {
            var cur = normalize(input.value, false);
            var next = clamp(cur + inputCfg.step, inputCfg.min, inputCfg.max);
            input.value = String(next);
            onChange(next);
        });

        input.addEventListener('input', function () {
            var maybe = Number(input.value);
            if (!isNaN(maybe)) {
                onChange(clamp(maybe, inputCfg.min, inputCfg.max));
            }
        });

        input.addEventListener('blur', function () {
            push(true);
        });

        input.addEventListener('wheel', function (e) {
            if (document.activeElement !== input) return;
            e.preventDefault();
            var cur = normalize(input.value, false);
            var next = e.deltaY < 0 ? cur + inputCfg.step : cur - inputCfg.step;
            next = clamp(next, inputCfg.min, inputCfg.max);
            input.value = String(next);
            onChange(next);
        }, { passive: false });

        counter.appendChild(minus);
        counter.appendChild(input);
        counter.appendChild(plus);

        wrap.appendChild(label);
        wrap.appendChild(counter);

        return {
            root: wrap,
            input: input
        };
    }

    var PModFields = {
        _instances: [],
        _listeners: [],

        init: function () {
            var cfg = window.pmodConfig || {};
            var products = cfg.products || {};
            var containers = document.querySelectorAll('.sku-props');
            if (!containers.length) return;

            var self = this;
            containers.forEach(function (container) {
                var productId = Number(container.dataset.itemId || 0);
                if (!productId || !products[productId]) return;

                var productCfg = products[productId];
                var customCfg = normalizeCustomConfig(productCfg.customConfig || {});
                if (!customCfg.fields.length) return;

                var instance = self.createInstance(container, productCfg, customCfg);
                self._instances.push(instance);
                instance.emit();
            });

            window.PModificatorFields = {
                getState: function () {
                    return self.getState();
                },
                onChange: function (fn) {
                    if (typeof fn === 'function') {
                        self._listeners.push(fn);
                    }
                },
                destroy: function () {
                    self.destroy();
                }
            };
        },

        createInstance: function (container, productCfg, customCfg) {
            var state = {};
            var fieldNodes = [];
            var self = this;

            var root = document.createElement('div');
            root.className = 'pmod-custom-fields';

            customCfg.fields.forEach(function (field, fieldIdx) {
                var block = document.createElement('div');
                block.className = 'pmod-custom-field';
                block.dataset.fieldId = field.id;
                block.dataset.fieldName = field.name;
                block.dataset.bindingCode = field.binding.skuPropertyCode || '';
                block.dataset.bindingPropId = String(field.binding.skuPropertyId || '');
                block.dataset.markerValue = field.binding.marker.value || '';
                block.dataset.markerXmlId = field.binding.marker.xmlId || '';

                var title = document.createElement('div');
                title.className = 'pmod-custom-field__title';
                title.textContent = field.name;
                block.appendChild(title);

                var values = [];
                field.inputs.forEach(function (inputCfg, inputIdx) {
                    var stateKey = field.inputs.length === 1
                        ? field.name
                        : (field.name + '_' + (inputIdx + 1));

                    var control = createNumberControl(inputCfg, inputCfg.min, function (val) {
                        values[inputIdx] = val;
                        state[stateKey] = val;
                        instance.emit();
                    });

                    values[inputIdx] = inputCfg.min !== null ? inputCfg.min : 0;
                    state[stateKey] = values[inputIdx];
                    block.appendChild(control.root);
                });

                root.appendChild(block);
                fieldNodes.push({
                    cfg: field,
                    node: block
                });
            });

            container.appendChild(root);

            function updateVisibility() {
                fieldNodes.forEach(function (item) {
                    var cfgField = item.cfg;
                    var bindId = cfgField.binding.skuPropertyId;
                    if (!bindId) {
                        item.node.style.display = '';
                        return;
                    }

                    var inner = container.querySelector('.sku-props__inner[data-id="' + bindId + '"]');
                    if (!inner) {
                        item.node.style.display = 'none';
                        return;
                    }

                    var active = inner.querySelector('.sku-props__value--active') || inner.querySelector('.sku-props__value');
                    if (!active) {
                        item.node.style.display = 'none';
                        return;
                    }

                    var activeText = String(active.dataset.title || active.textContent || '').trim();
                    var markerValue = String(cfgField.binding.marker.value || '').trim();

                    if (!markerValue) {
                        item.node.style.display = '';
                        return;
                    }

                    item.node.style.display = activeText === markerValue ? '' : 'none';
                });
            }

            container.addEventListener('click', function (e) {
                if (!e.target.closest('.sku-props__value')) return;
                updateVisibility();
            }, true);

            var instance = {
                container: container,
                state: state,
                emit: function () {
                    var payload = {
                        productId: Number(container.dataset.itemId || 0),
                        values: deepClone(state)
                    };

                    self._listeners.forEach(function (fn) {
                        try { fn(payload); } catch (err) {}
                    });

                    document.dispatchEvent(new CustomEvent('pmod:fields:change', {
                        detail: payload
                    }));
                },
                updateVisibility: updateVisibility
            };

            updateVisibility();
            return instance;
        },

        getState: function () {
            var out = {};
            this._instances.forEach(function (instance) {
                var pid = String(instance.container.dataset.itemId || '0');
                out[pid] = deepClone(instance.state);
            });
            return out;
        },

        destroy: function () {
            this._instances.forEach(function (instance) {
                var node = instance.container.querySelector('.pmod-custom-fields');
                if (node && node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            });
            this._instances = [];
            this._listeners = [];
            delete window.PModificatorFields;
        }
    };

    ready(function () {
        PModFields.init();
    });
}());
