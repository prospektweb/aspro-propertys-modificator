<?php

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;

/**
 * Thin orchestration layer for template bootstrap.
 *
 * Input: current page context.
 * Output: assets + window.pmodConfig injection, or early return.
 */
class TemplateBootstrap
{
    public function __construct(private ?OfferDataProvider $offerDataProvider = null)
    {
        $this->offerDataProvider = $this->offerDataProvider ?? new OfferDataProvider();
    }

    public function run(): void
    {
        if (!Loader::includeModule('prospektweb.propmodificator')) {
            return;
        }
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return;
        }

        $resolver = new ProductResolver(Config::getOffersIblockId(), Config::getProductsIblockId());
        $productId = $resolver->resolve();
        if (!$productId) {
            PageHandler::debugLog('No productId found, skipping. GET=' . json_encode($_GET));
            return;
        }

        $productData = $this->offerDataProvider->loadForProduct($productId);
        if ($productData === null) {
            PageHandler::debugLog('No custom JSON settings for productId=' . $productId . ', skipping');
            return;
        }

        $config = (new FrontendConfigBuilder())->build($productData);

        $jsDir = '/bitrix/js/prospektweb.propmodificator/';
        Asset::getInstance()->addJs($jsDir . 'state/store.js');
        Asset::getInstance()->addJs($jsDir . 'api/client.js');
        Asset::getInstance()->addJs($jsDir . 'pricing/interpolation.js');
        Asset::getInstance()->addJs($jsDir . 'ui/app.js');
        Asset::getInstance()->addJs($jsDir . 'script.js');
        Asset::getInstance()->addCss($jsDir . 'style.css');

        $jsonConfig = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        Asset::getInstance()->addString(
            '<script>window.pmodConfig = ' . $jsonConfig . ';</script>',
            false,
            AssetLocation::AFTER_CSS
        );

        PageHandler::debugLog('pmodConfig injected for productId=' . $productId . ', offers=' . count($productData['offers']));
    }
}
