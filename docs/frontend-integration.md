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
2. После `DOMContentLoaded` запускается `window.PModificator.init()` из `ui/app.js`.
3. `ui/app.js` выполняет только композицию модулей и:
   - находит контейнеры `.sku-props`,
   - поднимает состояние текущих выбранных свойств,
   - передаёт управление в специализированные модули.
4. При изменении пользовательских параметров:
   - выполняется локальная интерполяция через `pricing/interpolation.js`,
   - отправляется серверный пересчёт через `ajaxUrl`,
   - подтверждённая цена применяется в блоках цен/кнопке покупки.
5. При смене SKU и после финального события Аспро цена pmod применяется повторно, чтобы исключить «перетирание» базовой ценой оффера.

## Модульная структура

- `shared/utils.js`:
  - `debounce`,
  - `clamp`,
  - `formatPrice`,
  - `syncUrlPmodVolume`,
  - `ready`.
- `ui/app.js`:
  - композиция `PModControls`, `PModPricingMain`, `PModIntegration`, `PModBasket`,
  - `init()` и `initContainer()`.
- `ui/controls/index.js`:
  - рендер кастомных контролов формата и тиража,
  - обработчики событий ввода/кнопок,
  - работа с пресетами SKU.
- `pricing/interpolation.js`:
  - единый источник интерполяции (`linearInterp`, `bilinearInterp`, `findNeighbors`).
- `pricing/main-price.js`:
  - фильтрация офферов,
  - клиентские расчёты цены,
  - выбор `mainPrice`,
  - применение цен в DOM.
- `integration/aspro.js`:
  - интеграция с событиями Аспро (`onFinalActionSKUInfo`),
  - стабилизация и повторное применение pmod-цены после смены SKU,
  - серверный fetch-пересчёт.
- `basket/hook.js`:
  - инъекция hidden-полей перед add-to-cart,
  - patch `collectRequestData` и fetch-hook для корзины.

## Точки расширения

- Расширение логики клиента: `install/assets/js/prospektweb.propmodificator/script.js`.
- API-клиент: `install/assets/js/prospektweb.propmodificator/api/client.js`.
- Централизованное состояние: `install/assets/js/prospektweb.propmodificator/state/store.js`.
- Интерполяция/формулы: `install/assets/js/prospektweb.propmodificator/pricing/interpolation.js` и `install/assets/js/prospektweb.propmodificator/pricing/main-price.js`.
- Интеграция с DOM/Aspro: `install/assets/js/prospektweb.propmodificator/integration/aspro.js`.
- Кастомные поля корзины: `install/assets/js/prospektweb.propmodificator/basket/hook.js`.

## Минимальный чек интеграции

- На странице товара существует `.sku-props[data-item-id]`.
- В `<head>` присутствует `window.pmodConfig`.
- Загружены JS/CSS из `/bitrix/js/prospektweb.propmodificator/`.
