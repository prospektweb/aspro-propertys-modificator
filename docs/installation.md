# Установка, обновление, деинсталляция

## Требования

- 1С-Битрикс с модулями `iblock`, `catalog`, `sale`.
- PHP 8.0+.
- Рекомендуемое размещение: `/local/modules/prospektweb.propmodificator`.

## Установка

1. Скопируйте файлы модуля в `/local/modules/prospektweb.propmodificator/`.
2. В админке откройте **Marketplace → Установленные решения**.
3. Установите модуль **Модификатор свойств ТП (Аспро: Премьер)**.
4. На шаге мастера укажите/подтвердите ID инфоблока ТП и товаров.

Что делает установщик:
- регистрирует модуль и обработчики событий;
- копирует ассеты в `/bitrix/js/prospektweb.propmodificator/`;
- копирует endpoint в `/ajax/prospektweb.propmodificator/`;
- копирует admin-tools endpoint в `/bitrix/tools/prospektweb.propmodificator/`;
- создаёт include-файл подключения и регистрирует `OnEpilog`.

## Обновление

Рекомендуемый сценарий:
1. Обновите файлы модуля в `/local/modules/prospektweb.propmodificator/`.
2. Выполните стандартное обновление модуля через админку Bitrix.
3. Проверьте:
   - версию в `install/version.php`;
   - доступность ассетов `/bitrix/js/prospektweb.propmodificator/*`;
   - работоспособность `/ajax/prospektweb.propmodificator/calc_price.php`.

## Деинсталляция

1. В **Marketplace → Установленные решения** выберите удаление модуля.
2. На шаге подтверждения выберите, сохранять ли данные (`save_data=Y`).
3. При удалении модуль:
   - снимает обработчики событий;
   - удаляет публичные файлы в `/bitrix/js/...`, `/ajax/...`, `/bitrix/tools/...`;
   - отменяет регистрацию модуля;
   - опционально удаляет служебные свойства данных (при отключённом `save_data`).

## Примечания

- Для production предпочтительно держать модуль в `/local/modules`, а не в `/bitrix/modules`.
- После установки/обновления рекомендуется очистить кэш Bitrix.
