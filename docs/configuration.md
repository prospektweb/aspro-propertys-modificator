# Конфигурация модуля

Страница настроек: `/bitrix/admin/settings.php?mid=prospektweb.propmodificator`

## Опции модуля

| Ключ | По умолчанию | Назначение |
|---|---:|---|
| `OFFERS_IBLOCK_ID` | `15` | ID инфоблока торговых предложений |
| `PRODUCTS_IBLOCK_ID` | `14` | ID инфоблока товаров |
| `FORMAT_PROP_CODE` | `CALC_PROP_FORMAT` | Код SKU-свойства формата (обычно enum с XML_ID `WxH`) |
| `VOLUME_PROP_CODE` | `CALC_PROP_VOLUME` | Код SKU-свойства тиража |
| `CUSTOM_CONFIG_PROP_CODE` | `PMOD_CUSTOM_CONFIG` | Код свойства товара с JSON-конфигурацией калькулятора |
| `PRICE_TYPE_ID` | `1` | Базовый ID типа цены для служебных операций |
| `CATALOG_PATH_FILTER` | `/catalog/` | Фильтр URL для активации `OnEpilog` |
| `DEBUG` | `N` | Включение debug-лога в `/bitrix/logs/prospektweb.propmodificator.log` |

## Источник и приоритет

1. Значения по умолчанию — `default_option.php`.
2. Пользовательские значения — сохранённые через `options.php`.
3. Чтение в runtime — через `lib/Config.php`.

## Конфигурация товара

Калькулятор читает JSON из свойства товара `CUSTOM_CONFIG_PROP_CODE` (по умолчанию `PMOD_CUSTOM_CONFIG`).
Через `ProductConfigReader` из него извлекаются:

- `formatSettings` (ограничения по ширине/высоте/шагу),
- `volumeSettings` (ограничения по тиражу/шагу),
- полный `customConfig` для фронтенда.

Если конфигурация пуста, модуль для товара не активируется.
