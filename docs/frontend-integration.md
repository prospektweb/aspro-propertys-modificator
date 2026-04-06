# Frontend-интеграция

## `window.pmodConfig`

`TemplateBootstrap` добавляет в `<head>` объект `window.pmodConfig`.

Базовая структура:

```js
{
  ajaxUrl: '/ajax/prospektweb.propmodificator/calc_price.php',
  products: {
    [productId]: {
      formatPropId,
      volumePropId,
      formatPropCode,
      volumePropCode,
      formatSettings,
      volumeSettings,
      offers,
      volumeEnumMap,
      formatEnumMap,
      catalogGroups,
      canBuyGroups,
      allPropIds,
      skuPropCodeToId,
      roundingRules,
      initialVolume,
      customConfig
    }
  }
}
```

## Жизненный цикл UI

1. `script.js` (entrypoint) вызывает `PModStore.bootstrap(window.pmodConfig)`.
2. После `DOMContentLoaded` запускается `window.PModificator.init()`.
3. В `init()` модуль:
   - находит контейнеры `.sku-props`,
   - поднимает состояние текущих выбранных свойств,
   - добавляет/синхронизирует UI для пользовательского ввода,
   - вешает хуки на события SKU Аспро.
4. При изменении пользовательских параметров:
   - выполняется локальная интерполяция (для отзывчивости интерфейса),
   - отправляется серверный пересчёт через `ajaxUrl`,
   - подтверждённая цена применяется в блоках цен/кнопке покупки.
5. При смене SKU и после финального события Аспро цена pmod применяется повторно, чтобы исключить «перетирание» базовой ценой оффера.

## Точки расширения

- Расширение логики клиента: `install/assets/js/prospektweb.propmodificator/script.js`.
- API-клиент: `install/assets/js/prospektweb.propmodificator/api/client.js`.
- Централизованное состояние: `install/assets/js/prospektweb.propmodificator/state/store.js`.

## Минимальный чек интеграции

- На странице товара существует `.sku-props[data-item-id]`.
- В `<head>` присутствует `window.pmodConfig`.
- Загружены JS/CSS из `/bitrix/js/prospektweb.propmodificator/`.
