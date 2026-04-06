# aspro-propertys-modificator

## Конструктор пользовательских полей для карточки товара

**Версия:** 2.0.0  
**Разработчик:** [PROSPEKT-WEB](https://prospektweb.ru)  
**Идентификатор модуля:** `prospektweb.propmodificator`

### Описание

Модуль теперь выполняет только одну задачу: **рендер и управление пользовательскими полями** на карточке товара.

Фронтенд-часть:
- строит UI полей из JSON-конфига;
- хранит локальное состояние значений;
- применяет локальные show/hide условия;
- отдаёт текущее состояние через `CustomEvent('pmod:change')`, глобальный `window.pmodFieldsState` и `container.pmodFieldsApi`.

### Что модуль больше не делает

Из модуля полностью удалена бизнес-логика:
- расчёт и отображение цены;
- работа с SKU-переключениями;
- изменение `h1` / `title`;
- синхронизация с событиями Aspro (`BX.addCustomEvent`, `onFinalActionSKUInfo`);
- `MutationObserver` для карточки;
- debounce/stabilization/pending/revision механики;
- любые побочные DOM-изменения вне блока полей.

### Архитектура (после рефакторинга)

1. `template_include.php` подготавливает только `window.pmodConfig` с `customConfig` полями.
2. `script.js` инициализируется на `.sku-props` и рендерит поля.
3. Изменения значений обновляют локальный state и генерируют событие `pmod:change`.

### Формат состояния

Пример состояния, которое отдаёт модуль:

```json
{
  "quantity": 1200,
  "color": "4+4",
  "density": "300"
}
```

### Публичный API на контейнере

После инициализации контейнер `.sku-props` получает `pmodFieldsApi`:

- `getState()` — вернуть текущие значения;
- `exportConfig()` — экспортировать нормализованный конфиг полей;
- `importState(object)` — импортировать значения в UI и состояние.

### Подключение

Модуль подключается через `OnEpilog` и инжектит в `<head>`:
- `/bitrix/js/prospektweb.propmodificator/script.js`
- `/bitrix/js/prospektweb.propmodificator/style.css`
- `window.pmodConfig`

### Требования

- 1С-Битрикс с модулем `iblock`;
- корректно заполненный JSON-конфиг пользовательских полей в свойстве товара.
