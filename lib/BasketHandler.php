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
use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;

class BasketHandler
{
    private const SESSION_KEY = 'PMOD_CALC';

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
            return false;
        }

        $arFields['PRICE'] = $serverPrice;
        $arFields['CUSTOM_PRICE'] = 'Y';

        $_SESSION[self::SESSION_KEY] = $calcData + ['server_price' => $serverPrice];

        return true;
    }

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
            $propItem->setField('NAME', $arProp['NAME']);
            $propItem->setField('VALUE', $arProp['VALUE']);
            $propItem->setField('CODE', $arProp['CODE']);
        }
    }

    private static function getCalcDataFromRequest(): ?array
    {
        $raw = $_POST['prospekt_calc'] ?? null;

        if (!is_array($raw)) {
            return null;
        }

        return [
            'width' => isset($raw['width']) ? (int)$raw['width'] : null,
            'height' => isset($raw['height']) ? (int)$raw['height'] : null,
            'volume' => isset($raw['volume']) ? (int)$raw['volume'] : null,
            'custom_price' => isset($raw['custom_price']) ? (float)$raw['custom_price'] : null,
            'is_custom' => ($raw['is_custom'] ?? '') === 'Y' ? 'Y' : 'N',
            'product_id' => isset($raw['product_id']) ? (int)$raw['product_id'] : null,
            'other_props' => isset($raw['other_props']) && is_array($raw['other_props'])
                ? array_map('intval', $raw['other_props'])
                : null,
        ];
    }

    private static function validateCalcData(array $calcData, array $arFields): bool
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $productId = (int)($arFields['PRODUCT_ID'] ?? $calcData['product_id'] ?? 0);
        if (!$productId) {
            return false;
        }

        if (!ValidationRules::hasCustomInput($calcData['width'], $calcData['height'], $calcData['volume'])) {
            return false;
        }

        $reader = new ProductConfigReader();
        $settings = $reader->readByProductId($productId);

        return ValidationRules::validateInput(
            $calcData['width'],
            $calcData['height'],
            $calcData['volume'],
            $settings['formatSettings'] ?? [],
            $settings['volumeSettings'] ?? []
        );
    }

    private static function recalculatePrice(array $calcData, array $arFields): ?float
    {
        $productId = (int)($arFields['PRODUCT_ID'] ?? $calcData['product_id'] ?? 0);
        if (!$productId) {
            return null;
        }

        $pricing = (new PricingService())->calculate([
            'productId' => $productId,
            'volume' => $calcData['volume'],
            'width' => $calcData['width'],
            'height' => $calcData['height'],
            'basketQty' => 1,
            'visibleGroups' => [],
            'activeGroupId' => null,
            'otherProps' => $calcData['other_props'] ?? null,
            'debug' => false,
        ]);

        if (!$pricing['ok']) {
            return null;
        }

        $mainPrice = (new MainPriceResolver())->resolve(
            $pricing['rawPrices'],
            $pricing['rangePrices'],
            $pricing['catalogGroups'],
            $pricing['accessibleGroupIds'],
            1,
            [],
            null
        );

        return $mainPrice ? (float)$mainPrice['price'] : null;
    }

    private static function buildBasketProperties(array $calcData): array
    {
        $props = [];

        if ($calcData['width'] !== null && $calcData['height'] !== null) {
            $props[] = [
                'NAME' => 'Формат (Ш×В)',
                'VALUE' => $calcData['width'] . '×' . $calcData['height'] . ' мм',
                'CODE' => 'PMOD_FORMAT',
            ];
        }

        if ($calcData['volume'] !== null) {
            $props[] = [
                'NAME' => 'Тираж',
                'VALUE' => number_format($calcData['volume'], 0, '.', ' ') . ' шт.',
                'CODE' => 'PMOD_VOLUME',
            ];
        }

        if (!empty($calcData['server_price'])) {
            $props[] = [
                'NAME' => 'Расчётная цена',
                'VALUE' => number_format((float)$calcData['server_price'], 2, '.', ' ') . ' руб.',
                'CODE' => 'PMOD_PRICE',
            ];
        }

        return $props;
    }
}
