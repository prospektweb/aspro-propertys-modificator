<?php
/**
 * Подключение модуля в шаблоне Аспро: Премьер
 *
 * Рекомендуемый способ подключения — через событие OnEpilogHtml
 * (регистрируется установщиком модуля автоматически).
 *
 * Альтернативный способ — добавить в /local/php_interface/init.php:
 *
 *   \Bitrix\Main\EventManager::getInstance()->addEventHandler(
 *       'main', 'OnEpilogHtml',
 *       function () {
 *           if (!\Bitrix\Main\Loader::includeModule('prospektweb.propmodificator')) return;
 *           $f = \Bitrix\Main\Application::getDocumentRoot()
 *               . getLocalPath('modules/prospektweb.propmodificator/template_include.php');
 *           if (file_exists($f)) include_once $f;
 *       }
 *   );
 *
 * Файл:
 *  1. Подключает JS и CSS модуля через Asset API (совместимо с кешем/композитом)
 *  2. Читает свойства текущего товара (SET_FORMAT, SET_VOLUME)
 *  3. Читает все ТП с их свойствами и ценами
 *  4. Инжектирует конфигурационный объект window.pmodConfig в <head> страницы
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\PropertyValidator;
use Bitrix\Catalog\PriceTable;

// Загружаем модуль
if (!Loader::includeModule('prospektweb.propmodificator')) {
    return;
}

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    return;
}

// ─── Определяем текущий товар ─────────────────────────────────────────────────

$productId = (int)(
    $GLOBALS['ELEMENT_ID']
    ?? $GLOBALS['arResult']['ID']
    ?? $GLOBALS['arParams']['ELEMENT_ID']
    ?? 0
);

if (!$productId) {
    // Попытка определить товар по переменным шаблона Аспро
    if (!empty($GLOBALS['arResult']['OFFERS'])) {
        $firstOffer = reset($GLOBALS['arResult']['OFFERS']);
        $productId  = (int)($firstOffer['PROPERTY_CML2_LINK_VALUE'] ?? 0);
    }
}

if (!$productId) {
    return;
}

// ─── Конфигурация ─────────────────────────────────────────────────────────────

$offersIblockId   = Config::getOffersIblockId();
$productsIblockId = Config::getProductsIblockId();
$formatPropCode   = Config::getFormatPropCode();
$volumePropCode   = Config::getVolumePropCode();
$setFormatCode    = Config::getSetFormatPropCode();
$setVolumeCode    = Config::getSetVolumePropCode();
$priceTypeId      = Config::getPriceTypeId();

// ─── Читаем настройки товара (SET_FORMAT, SET_VOLUME) ─────────────────────────

$rsProduct = CIBlockElement::GetByID($productId);
$arProduct = $rsProduct->GetNextElement();

$formatSettings = [];
$volumeSettings = [];
$formatPropId   = null;
$volumePropId   = null;

if ($arProduct) {
    // Загружаем свойства по одному с фильтром по CODE (строка, не массив)
    // чтобы избежать TypeError в старых версиях API и не загружать все свойства
    $arFormatProps = $arProduct->GetProperties([], ['CODE' => $setFormatCode]);
    $arVolumeProps = $arProduct->GetProperties([], ['CODE' => $setVolumeCode]);

    if (!empty($arFormatProps[$setFormatCode]['VALUE'])) {
        foreach ((array)$arFormatProps[$setFormatCode]['VALUE'] as $idx => $key) {
            $val = $arFormatProps[$setFormatCode]['DESCRIPTION'][$idx] ?? null;
            if ($key && $val !== null) {
                $formatSettings[strtoupper(trim($key))] = (int)$val;
            }
        }
    }

    if (!empty($arVolumeProps[$setVolumeCode]['VALUE'])) {
        foreach ((array)$arVolumeProps[$setVolumeCode]['VALUE'] as $idx => $key) {
            $val = $arVolumeProps[$setVolumeCode]['DESCRIPTION'][$idx] ?? null;
            if ($key && $val !== null) {
                $volumeSettings[strtoupper(trim($key))] = (int)$val;
            }
        }
    }
}

// Если нет настроек — модуль не нужен для этого товара
if (empty($formatSettings) && empty($volumeSettings)) {
    return;
}

// ─── Определяем ID свойств в инфоблоке ТП ────────────────────────────────────

if ($formatPropCode) {
    $rsProp = CIBlockProperty::GetList([], [
        'IBLOCK_ID' => $offersIblockId,
        'CODE'      => $formatPropCode,
        'ACTIVE'    => 'Y',
    ]);
    if ($arProp = $rsProp->Fetch()) {
        $formatPropId = (int)$arProp['ID'];
    }
}

if ($volumePropCode) {
    $rsProp = CIBlockProperty::GetList([], [
        'IBLOCK_ID' => $offersIblockId,
        'CODE'      => $volumePropCode,
        'ACTIVE'    => 'Y',
    ]);
    if ($arProp = $rsProp->Fetch()) {
        $volumePropId = (int)$arProp['ID'];
    }
}

// ─── Загружаем ТП товара ──────────────────────────────────────────────────────

$offers = [];
$offerIds = [];

$rsOffers = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    [
        'IBLOCK_ID'            => $offersIblockId,
        'PROPERTY_CML2_LINK'   => $productId,
        'ACTIVE'               => 'Y',
    ],
    false,
    false,
    [
        'ID',
        'NAME',
        "PROPERTY_{$formatPropCode}",
        "PROPERTY_{$volumePropCode}",
    ]
);

while ($arOffer = $rsOffers->Fetch()) {
    $offerId = (int)$arOffer['ID'];

    $formatXmlId = $arOffer["PROPERTY_{$formatPropCode}_VALUE_XML_ID"] ?? null;
    $volumeXmlId = $arOffer["PROPERTY_{$volumePropCode}_VALUE_XML_ID"] ?? null;

    $formatParsed = $formatXmlId ? PropertyValidator::parseFormatXmlId($formatXmlId) : null;
    $volumeParsed = $volumeXmlId ? PropertyValidator::parseVolumeXmlId($volumeXmlId) : null;

    $offers[$offerId] = [
        'id'     => $offerId,
        'name'   => $arOffer['NAME'],
        'width'  => $formatParsed['width']  ?? null,
        'height' => $formatParsed['height'] ?? null,
        'volume' => $volumeParsed,
        'price'  => null,
    ];

    $offerIds[] = $offerId;
}

// ─── Загружаем цены ───────────────────────────────────────────────────────────

if (!empty($offerIds)) {
    $rsPrices = PriceTable::getList([
        'filter' => [
            '=PRODUCT_ID'       => $offerIds,
            '=CATALOG_GROUP_ID' => $priceTypeId,
        ],
        'select' => ['PRODUCT_ID', 'PRICE'],
    ]);

    while ($arPrice = $rsPrices->fetch()) {
        $id = (int)$arPrice['PRODUCT_ID'];
        if (isset($offers[$id])) {
            $offers[$id]['price'] = (float)$arPrice['PRICE'];
        }
    }
}

// ─── Формируем конфиг для JS ──────────────────────────────────────────────────

$pmodConfig = [
    'products' => [
        $productId => [
            'formatPropId'    => $formatPropId,
            'volumePropId'    => $volumePropId,
            'formatSettings'  => $formatSettings,
            'volumeSettings'  => $volumeSettings,
            'offers'          => array_values($offers),
        ],
    ],
];

// ─── Подключаем ассеты через Asset API (совместимо с кешем и композитом) ───

$jsDir = '/bitrix/js/prospektweb.propmodificator/';

Asset::getInstance()->addJs($jsDir . 'script.js');
Asset::getInstance()->addCss($jsDir . 'style.css');

// ─── Инжектируем конфиг в <head> через Asset API ──────────────────────────

$jsonConfig = json_encode($pmodConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

Asset::getInstance()->addString(
    '<script>window.pmodConfig = ' . $jsonConfig . ';</script>',
    false,
    AssetLocation::AFTER_CSS
);
