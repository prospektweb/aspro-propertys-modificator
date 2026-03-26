<?php
/**
 * Обработчик корзины для модуля prospektweb.propmodificator.
 *
 * Перехватывает добавление в корзину, если переданы произвольные значения
 * формата и/или тиража, пересчитывает цену на сервере и записывает
 * пользовательские свойства в позицию корзины.
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;

class BasketHandler
{
    private const SESSION_KEY = 'PMOD_CALC';

    // ─── Обработчики событий ──────────────────────────────────────────────────

    /**
     * Событие OnBeforeBasketAdd (совместимое с D7 и старым API).
     *
     * @param array $arFields Поля добавляемой позиции (передаётся по ссылке)
     * @return bool|null      false — прервать добавление
     */
    public static function onBeforeBasketAdd(array &$arFields): ?bool
    {
        $calcData = self::getCalcDataFromRequest();

        if (!$calcData || $calcData['is_custom'] !== 'Y') {
            return true;
        }

        if (!self::validateCalcData($calcData, $arFields)) {
            return false;
        }

        $serverPrice = self::recalculatePrice($calcData, $arFields);

        if ($serverPrice === null) {
            // Не удалось рассчитать — блокируем добавление с произвольной ценой
            return false;
        }

        // Устанавливаем рассчитанную цену
        $arFields['PRICE']        = $serverPrice;
        $arFields['CUSTOM_PRICE'] = 'Y';

        // Сохраняем кастомные данные в сессии для последующего события
        $_SESSION[self::SESSION_KEY] = $calcData + ['server_price' => $serverPrice];

        return true;
    }

    /**
     * Событие OnBeforeSaleBasketItemSetFields — записываем свойства позиции корзины.
     *
     * @param \Bitrix\Sale\BasketItem $basketItem
     */
    public static function onBeforeSaleBasketItemSetFields($basketItem): void
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $calcData = $_SESSION[self::SESSION_KEY];
        unset($_SESSION[self::SESSION_KEY]);

        if (!($calcData['is_custom'] === 'Y')) {
            return;
        }

        $props = self::buildBasketProperties($calcData);

        if (!method_exists($basketItem, 'getPropertyCollection')) {
            return;
        }

        $propCollection = $basketItem->getPropertyCollection();

        foreach ($props as $arProp) {
            $propItem = $propCollection->createItem();
            $propItem->setField('NAME',  $arProp['NAME']);
            $propItem->setField('VALUE', $arProp['VALUE']);
            $propItem->setField('CODE',  $arProp['CODE']);
        }
    }

    // ─── Вспомогательные методы ───────────────────────────────────────────────

    /**
     * Читаем данные калькулятора из текущего запроса.
     * Поля передаются как: prospekt_calc[width], prospekt_calc[height] и т.д.
     *
     * @return array|null
     */
    private static function getCalcDataFromRequest(): ?array
    {
        $raw = $_POST['prospekt_calc'] ?? null;

        if (!is_array($raw)) {
            return null;
        }

        return [
            'width'        => isset($raw['width'])        ? (int)$raw['width']        : null,
            'height'       => isset($raw['height'])       ? (int)$raw['height']       : null,
            'volume'       => isset($raw['volume'])       ? (int)$raw['volume']       : null,
            'custom_price' => isset($raw['custom_price']) ? (float)$raw['custom_price'] : null,
            'is_custom'    => ($raw['is_custom'] ?? '') === 'Y' ? 'Y' : 'N',
            'product_id'   => isset($raw['product_id'])  ? (int)$raw['product_id']   : null,
        ];
    }

    /**
     * Валидируем пользовательские значения (мин/макс/шаг из SET_FORMAT и SET_VOLUME).
     *
     * @param array $calcData   Данные из запроса
     * @param array $arFields   Поля позиции корзины (содержит PRODUCT_ID)
     * @return bool
     */
    private static function validateCalcData(array $calcData, array $arFields): bool
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $productId = (int)($arFields['PRODUCT_ID'] ?? $calcData['product_id'] ?? 0);
        if (!$productId) {
            return false;
        }

        $settings = self::getProductSettings($productId);

        // Валидируем FORMAT
        if ($calcData['width'] !== null && $calcData['height'] !== null) {
            $fmtCfg = $settings['format'];
            if (!empty($fmtCfg)) {
                if ($calcData['width']  < $fmtCfg['MIN_WIDTH']  || $calcData['width']  > $fmtCfg['MAX_WIDTH']) {
                    return false;
                }
                if ($calcData['height'] < $fmtCfg['MIN_HEIGHT'] || $calcData['height'] > $fmtCfg['MAX_HEIGHT']) {
                    return false;
                }
            }
        }

        // Валидируем VOLUME
        if ($calcData['volume'] !== null) {
            $volCfg = $settings['volume'];
            if (!empty($volCfg)) {
                if ($calcData['volume'] < $volCfg['MIN'] || $calcData['volume'] > $volCfg['MAX']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Пересчёт цены на сервере.
     *
     * @return float|null
     */
    private static function recalculatePrice(array $calcData, array $arFields): ?float
    {
        $productId = (int)($arFields['PRODUCT_ID'] ?? $calcData['product_id'] ?? 0);
        if (!$productId) {
            return null;
        }

        $interpolator = new PriceInterpolator($productId);

        return $interpolator->interpolate(
            $calcData['width'],
            $calcData['height'],
            $calcData['volume']
        );
    }

    /**
     * Формируем массив свойств позиции корзины.
     *
     * @param array $calcData
     * @return array
     */
    private static function buildBasketProperties(array $calcData): array
    {
        $props = [];

        if ($calcData['width'] !== null && $calcData['height'] !== null) {
            $props[] = [
                'NAME'  => 'Формат (Ш×В)',
                'VALUE' => $calcData['width'] . '×' . $calcData['height'] . ' мм',
                'CODE'  => 'PMOD_FORMAT',
            ];
        }

        if ($calcData['volume'] !== null) {
            $props[] = [
                'NAME'  => 'Тираж',
                'VALUE' => number_format($calcData['volume'], 0, '.', ' ') . ' шт.',
                'CODE'  => 'PMOD_VOLUME',
            ];
        }

        if (!empty($calcData['server_price'])) {
            $props[] = [
                'NAME'  => 'Расчётная цена',
                'VALUE' => number_format((float)$calcData['server_price'], 2, '.', ' ') . ' руб.',
                'CODE'  => 'PMOD_PRICE',
            ];
        }

        return $props;
    }

    /**
     * Читаем настройки SET_FORMAT и SET_VOLUME из свойств товара.
     *
     * @param int $productId
     * @return array{format: array, volume: array}
     */
    private static function getProductSettings(int $productId): array
    {
        $formatCode = Config::getSetFormatPropCode();
        $volumeCode = Config::getSetVolumePropCode();
        $iblockId   = Config::getProductsIblockId();

        $rsElement = \CIBlockElement::GetByID($productId);
        if (!($arElement = $rsElement->GetNextElement())) {
            return ['format' => [], 'volume' => []];
        }

        $arProps = $arElement->GetProperties([], ['CODE' => [$formatCode, $volumeCode]]);

        $format = [];
        $volume = [];

        if (!empty($arProps[$formatCode]['VALUE'])) {
            foreach ((array)$arProps[$formatCode]['VALUE'] as $idx => $key) {
                $val = $arProps[$formatCode]['DESCRIPTION'][$idx] ?? null;
                if ($key && $val !== null) {
                    $format[strtoupper($key)] = (int)$val;
                }
            }
        }

        if (!empty($arProps[$volumeCode]['VALUE'])) {
            foreach ((array)$arProps[$volumeCode]['VALUE'] as $idx => $key) {
                $val = $arProps[$volumeCode]['DESCRIPTION'][$idx] ?? null;
                if ($key && $val !== null) {
                    $volume[strtoupper($key)] = (int)$val;
                }
            }
        }

        return ['format' => $format, 'volume' => $volume];
    }
}
