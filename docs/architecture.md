# Архитектура модуля

## Слои

1. **Интеграция с Bitrix событиями**
   - `PageHandler::onEpilog()` — точка входа на публичной карточке товара.
   - `AdminHandler::onProlog()` — подключение admin-builder в админке товара.
   - `BasketHandler::*` — фиксация пользовательских параметров в корзине.

2. **Application / orchestration**
   - `TemplateBootstrap` — подгружает данные товара, добавляет JS/CSS, инжектит `window.pmodConfig`.
   - `AjaxController` — orchestration endpoint-а расчёта (`calc_price.php`).

3. **Domain**
   - `PricingService` — валидация входа, загрузка цен ТП, интерполяция, округление.
   - `PriceInterpolator` — линейная/билинейная интерполяция с делегированием mode-специфики в `FieldModeHandlerInterface`.
   - DTO: `CalcPriceRequest`, `CalcPriceResult`, `BasketCalcData`.

4. **Infrastructure**
   - `Infrastructure\Bitrix\*Repository` — чтение офферов/цен/конфигурации/групп цен из Bitrix.
   - `Infrastructure\Bitrix\BitrixPropertyBindingResolver` — разрешение связки `mode -> SKU property -> enum XML_ID`.
   - `Infrastructure\Bitrix\FieldMode\*Handler` — mode-специфичный парсинг XML_ID и подготовка точек интерполяции.
   - `Infrastructure\Http\*` — адаптеры входящего запроса и троттлинг.

## Поток данных

1. `OnEpilog` вызывает `template_include.php`.
2. `TemplateBootstrap` определяет `productId` через `ProductResolver`.
3. `OfferDataProvider` собирает данные ТП + пользовательскую конфигурацию товара.
4. `FrontendConfigBuilder` формирует payload и инжектит `window.pmodConfig`.
5. Frontend (`script.js`) поднимает UI, читает состояние SKU и отправляет запрос на `/ajax/prospektweb.propmodificator/calc_price.php`.
6. `AjaxController` парсит и валидирует вход, вызывает `PricingService`, через `ResponseFactory` возвращает нормализованный ответ.
7. Frontend применяет рассчитанную цену в UI; при добавлении в корзину серверный обработчик повторно валидирует и фиксирует данные.

## Ключевые классы

- **Точка входа страницы:** `lib/PageHandler.php`, `template_include.php`, `lib/TemplateBootstrap.php`.
- **Формирование фронтенд-конфига:** `lib/OfferDataProvider.php`, `lib/FrontendConfigBuilder.php`.
- **Расчёт цены:** `lib/AjaxController.php`, `lib/RequestParser.php`, `lib/PricingService.php`, `lib/PriceInterpolator.php`, `lib/ResponseFactory.php`.
- **Доступ к данным Bitrix:** `lib/Infrastructure/Bitrix/*`.
- **Конфигурация модуля:** `lib/Config.php`, `default_option.php`, `options.php`.

## Extension Points

### 1) Как добавить новый режим поля

1. Создать backend-обработчик режима, реализующий `FieldModeHandlerInterface`.
   - Референс: `Infrastructure\Bitrix\FieldMode\FormatFieldModeHandler`.
   - Референс: `Infrastructure\Bitrix\FieldMode\VolumeFieldModeHandler`.
2. Подключить режим в местах оркестрации:
   - чтение и парсинг SKU enum XML_ID (`OfferDataProvider`, `Infrastructure\Bitrix\OfferRepository`);
   - интерполяция (`PriceInterpolator`);
   - валидация пользовательского ввода (`ValidationRules`, `RequestParser`, DTO если нужно).
3. На фронте добавить зеркальный обработчик режима (структура `FieldModeHandlerInterface` в `pricing/field-mode-handlers.js`) и учитывать его в UI-логике `ui/app.js`.

### 2) Как связать режим с SKU-свойством

- Использовать `PropertyBindingResolverInterface` (референс-реализация: `BitrixPropertyBindingResolver`).
- Для режима должен быть определён `skuPropertyCode`/`getPropertyCode()`.
- Разрешение происходит в 2 шага:
  1. `propertyCode -> propertyId` через `resolvePropertyId(...)`.
  2. `propertyId -> enumId:xmlId` через `loadEnumXmlMap(...)`.
- Далее mode handler парсит XML_ID в доменное значение (например, `210x297` -> `{width,height}`, `1000` -> `1000`).

### 3) Где учесть режим в backend-интерполяции и валидации

- **Интерполяция:** `PriceInterpolator` (linear/bilinear ветки + fallback).
- **Валидация XML_ID значений SKU:** `PropertyValidator` (делегирует в mode handlers).
- **Валидация пользовательского ввода:** `ValidationRules` + нормализация в `RequestParser` / `CalcPriceRequest`.
- **Выборки из Bitrix:** `OfferDataProvider`, `Infrastructure\Bitrix\OfferRepository` (парсинг enum/XML_ID и фильтрация).

## Интерфейсы/абстракции

### Backend: `FieldModeHandlerInterface`

Назначение: инкапсулировать mode-специфичную логику парсинга XML_ID, определения custom-ввода и подготовки линейных точек интерполяции.

Ключевые методы:
- `getMode()`
- `getPropertyCode()`
- `isValidXmlId()` / `parseXmlId()`
- `hasCustomInput()`
- `extractLinearPoints()` / `resolveLinearValue()`

### Backend: `PropertyBindingResolverInterface`

Назначение: единообразно получать связки SKU-свойств из Bitrix (ID свойства и enum map).

Ключевые методы:
- `resolvePropertyId(int $iblockId, string $propertyCode): ?int`
- `loadEnumXmlMap(?int $propertyId): array<int,string>`

### Frontend: `FieldModeHandlerInterface` (JSDoc контракт)

Определён в `install/assets/js/prospektweb.propmodificator/pricing/field-mode-handlers.js` как структура:
- `mode`
- `skuPropertyCode`
- `hasCustomInput(state)`
- `getLinearKey(offer)`
- `getRequestedKey(state)`

## Референс-реализации format/volume

- Backend:
  - `FormatFieldModeHandler`
  - `VolumeFieldModeHandler`
- Frontend:
  - `window.PModFieldModeHandlers.format`
  - `window.PModFieldModeHandlers.volume`

## Минимальный пример нового поля: «плотность»

Ниже — минимальный список точек, которые нужно затронуть для нового режима `density`:

1. **Backend mode handler**
   - Добавить `Infrastructure\Bitrix\FieldMode\DensityFieldModeHandler` (реализует `FieldModeHandlerInterface`).
2. **Привязка SKU свойства**
   - Добавить код свойства (например, `CALC_PROP_DENSITY`) в конфиг и в места сборки фронтенд-конфига.
   - Использовать `PropertyBindingResolverInterface` для `propertyId/enumMap`.
3. **Парсинг офферов**
   - Расширить `OfferDataProvider::loadOffers()` и `Infrastructure\Bitrix\OfferRepository::loadOfferMetadata()` для чтения/парсинга density enum XML_ID.
4. **Интерполяция**
   - Добавить ветку расчёта в `PriceInterpolator` (линейно по density или как часть многомерной интерполяции).
5. **Валидация**
   - Добавить правила в `ValidationRules` и входной DTO/парсер (`RequestParser`, `CalcPriceRequest`).
6. **Frontend**
   - Добавить обработчик в `pricing/field-mode-handlers.js`.
   - Добавить UI-поле и сбор значения в `ui/app.js`.
7. **Интеграция ассетов/автозагрузки**
   - Подключить новые классы в `include.php` и (при необходимости) новый JS asset в `TemplateBootstrap`.
