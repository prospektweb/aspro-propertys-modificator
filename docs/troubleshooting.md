# Troubleshooting

## 1) Модуль не активируется на странице товара

**Симптомы:** нет `window.pmodConfig`, не подгружены JS/CSS.

Проверки:
1. Включите опцию `DEBUG=Y`.
2. Проверьте лог: `/bitrix/logs/prospektweb.propmodificator.log`.
3. Убедитесь, что URL страницы проходит `CATALOG_PATH_FILTER`.
4. Убедитесь, что у товара заполнено свойство `PMOD_CUSTOM_CONFIG` (или актуальный `CUSTOM_CONFIG_PROP_CODE`).

## 2) Есть UI, но цена не пересчитывается

Проверки:
1. В DevTools проверьте запрос в `/ajax/prospektweb.propmodificator/calc_price.php`.
2. Убедитесь, что отправляется валидный `sessid`.
3. Проверьте коды ошибок (`INVALID_*`, `MISSING_DIMENSIONS`, `PRICE_CALC_FAILED`).
4. Убедитесь, что среди офферов есть точки для интерполяции по переданным параметрам.

## 3) Ответ `THROTTLED`

Причина: слишком частые запросы (лимит ~300ms).

Решение:
- добавить/увеличить debounce клиентских вызовов;
- избегать параллельных повторных запросов на каждое микрособытие UI.

## 4) Неверные ограничения формата/тиража

Проверки:
- корректность JSON в `PMOD_CUSTOM_CONFIG`;
- соответствие кодов свойств в настройках модуля (`FORMAT_PROP_CODE`, `VOLUME_PROP_CODE`, `CUSTOM_CONFIG_PROP_CODE`).

## 5) Некорректная цена в корзине

Проверки:
- обработчики `OnBeforeBasketAdd` и `OnBeforeSaleBasketItemSetFields` зарегистрированы;
- при добавлении товара передаются пользовательские параметры;
- серверный пересчёт доступен и не возвращает ошибку.

## Быстрый JS-чек на странице товара

```js
(() => {
  const has = n => document.head.querySelectorAll(`[src*="${n}"],[href*="${n}"]`).length;
  console.table({
    hasPmodConfig: !!window.pmodConfig,
    pmodScriptInHead: has('prospektweb.propmodificator/script'),
    pmodStyleInHead: has('prospektweb.propmodificator/style')
  });
})();
```
