# API: `calc_price`

## Endpoint

- **URL:** `/ajax/prospektweb.propmodificator/calc_price.php`
- **Method:** `POST`
- **Content-Type ответа:** `application/json; charset=utf-8`

## Требования безопасности

- Обязателен валидный Bitrix `sessid` (проверка `check_bitrix_sessid()`).
- Включён троттлинг: минимальный интервал между запросами ≈ `300ms` на связку IP + session.

## Входной контракт

Поддерживаемые поля POST:

| Поле | Тип | Обязательно | Ограничения |
|---|---|---|---|
| `productId` | int | да | 1..2 000 000 000 |
| `volume` | int\|null | нет | 1..1 000 000 |
| `width` | int\|null | нет | 1..100 000 |
| `height` | int\|null | нет | 1..100 000 |
| `basket_qty` | int | нет | 1..10 000, по умолчанию `1` |
| `visible_groups` | int[] | нет | до 100 элементов |
| `active_group_id` | int\|null | нет | 1..100 000 |
| `other_props` | map<int,int> | нет | до 100 пар |
| `debug` | `Y`\|`N` | нет | включает debug-блок в ответе |
| `sessid` | string | да | Bitrix CSRF token |

Поддерживаемые поля GET:
- `productId` (fallback, если не пришёл в POST).

Любые неизвестные **POST**-поля приводят к ошибке `INVALID_PAYLOAD_FIELD`.
Неизвестные **GET**-поля игнорируются; из GET читается только `productId`.

## Успешный ответ

```json
{
  "success": true,
  "prices": {
    "1": {
      "raw": 1234.56,
      "formatted": "1 234.56 ₽",
      "groupName": "Базовая",
      "canBuy": true
    }
  },
  "ranges": {
    "1": [{ "from": 1, "to": 99, "price": 1234.56 }]
  },
  "mainPrice": {
    "raw": 1234.56,
    "formatted": "1 234.56 ₽",
    "groupId": 1
  },
  "meta": {
    "currency": "RUB",
    "vatIncluded": true,
    "roundingApplied": true
  },
  "requestId": "pmod_..."
}
```

## Ошибки

Базовый формат:

```json
{
  "success": false,
  "errorCode": "SOME_CODE",
  "error": "human readable message",
  "errorDetails": {}
}
```

Частые `errorCode`:
- `METHOD_NOT_ALLOWED` — запрос не POST.
- `INVALID_SESSID` — невалидный CSRF токен.
- `THROTTLED` — слишком частые запросы (`errorDetails.retryAfterMs`).
- `MODULE_NOT_LOADED` / `MODULES_NOT_LOADED`.
- `INVALID_*` (`PRODUCT_ID`, `WIDTH`, `HEIGHT`, `VOLUME`, ...).
- `MISSING_DIMENSIONS` — не переданы данные для расчёта.
- `PRICE_CALC_FAILED` — расчёт невозможен по текущим данным.

## Замечания по интеграции

- Серверная часть валидирует границы и состав payload; не полагайтесь только на клиентскую валидацию.
- Рекомендуется дебаунсить запросы на клиенте (в модуле используется 300ms).

## Пример клиентского вызова (фактический фронтенд)

```js
var sessid = (typeof BX !== 'undefined' && BX.bitrix_sessid)
    ? BX.bitrix_sessid()
    : ((typeof window.bitrix_sessid !== 'undefined') ? window.bitrix_sessid : '');

window.PModApi.postForm('/ajax/prospektweb.propmodificator/calc_price.php', {
    productId: 123,
    basket_qty: 1,
    width: 100,
    height: 50,
    active_group_id: 2,
    visible_groups: [2, 3],
    other_props: { 10: 101, 11: 203 },
    sessid: sessid || ''
}).then(function (data) {
    if (!data || !data.success) {
        console.warn('[pmod] calc_price error', data);
        return;
    }
    console.log('[pmod] main price', data.mainPrice);
});
```

- `window.PModApi.postForm(...)` отправляет payload в `FormData` (`multipart/form-data`) формате.
- `visible_groups` сериализуется как `visible_groups[]`.
- `other_props` сериализуется как `other_props[<ID>]=<VALUE>`.
- `credentials: 'same-origin'` включён внутри `PModApi`.
