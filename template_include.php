<?php
/**
 * Подключение фронтенд-модуля конструктора пользовательских полей.
 *
 * Файл:
 *  1) подключает JS/CSS ассеты;
 *  2) читает JSON-конфиг конструктора полей из свойства товара;
 *  3) инжектирует window.pmodConfig в <head>.
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\CustomConfig;
use Prospektweb\PropModificator\PageHandler;

if (!Loader::includeModule('prospektweb.propmodificator')) {
    return;
}

if (!Loader::includeModule('iblock')) {
    return;
}

$productsIblockId = Config::getProductsIblockId();
$customConfigCode = Config::getCustomConfigPropCode();

$productId = (int)(
    $GLOBALS['ELEMENT_ID']
    ?? $GLOBALS['arResult']['ID']
    ?? $GLOBALS['arParams']['ELEMENT_ID']
    ?? 0
);

if (!$productId) {
    foreach (['id', 'element_id', 'product_id'] as $param) {
        $val = (int)($_GET[$param] ?? 0);
        if ($val > 0) {
            $productId = $val;
            break;
        }
    }
}

if (!$productId && $productsIblockId > 0) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($requestPath) {
        $lastSegment = basename(rtrim($requestPath, '/'));
        if ($lastSegment && !is_numeric($lastSegment)) {
            $rsEl = CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $productsIblockId, 'CODE' => $lastSegment, 'ACTIVE' => 'Y'],
                false,
                ['nTopCount' => 1],
                ['ID']
            );
            if ($arEl = $rsEl->Fetch()) {
                $productId = (int)$arEl['ID'];
            }
        }
    }
}

if (!$productId) {
    PageHandler::debugLog('No productId found, skipping. GET=' . json_encode($_GET));
    return;
}

$rsProduct = CIBlockElement::GetByID($productId);
$arProduct = $rsProduct ? $rsProduct->GetNextElement() : null;

$customConfig = [];

if ($arProduct && $customConfigCode !== '') {
    $props = $arProduct->GetProperties([], []);
    $propPayload = $props[$customConfigCode] ?? null;
    $rawConfigValue = is_array($propPayload)
        ? ($propPayload['~VALUE'] ?? $propPayload['VALUE'] ?? null)
        : null;

    $customConfig = CustomConfig::parseFromPropertyValue($rawConfigValue);
}

if (empty($customConfig['fields'])) {
    PageHandler::debugLog('No custom fields config for productId=' . $productId . ', skipping');
    return;
}

$pmodConfig = [
    'products' => [
        $productId => [
            'customConfig' => $customConfig,
        ],
    ],
];

$jsDir = '/bitrix/js/prospektweb.propmodificator/';
Asset::getInstance()->addJs($jsDir . 'script.js');
Asset::getInstance()->addCss($jsDir . 'style.css');

$jsonConfig = json_encode($pmodConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
Asset::getInstance()->addString(
    '<script>window.pmodConfig = ' . $jsonConfig . ';</script>',
    false,
    AssetLocation::AFTER_CSS
);

PageHandler::debugLog('pmodConfig injected for productId=' . $productId . ', fields=' . count($customConfig['fields']));
