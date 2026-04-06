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
   - `PriceInterpolator` — линейная/билинейная интерполяция.
   - DTO: `CalcPriceRequest`, `CalcPriceResult`, `BasketCalcData`.

4. **Infrastructure**
   - `Infrastructure\Bitrix\*Repository` — чтение офферов/цен/конфигурации/групп цен из Bitrix.
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
