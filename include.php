<?php
/**
 * Автозагрузка классов модуля prospektweb.propmodificator
 *
 * Этот файл подключается Битриксом при загрузке модуля.
 * Регистрирует классы Prospektweb\PropModificator.
 */

use Bitrix\Main\Loader;

Loader::registerAutoloadClasses('prospektweb.propmodificator', [
    'Prospektweb\\PropModificator\\Config'             => 'lib/Config.php',
    'Prospektweb\\PropModificator\\PropertyValidator'  => 'lib/PropertyValidator.php',
    'Prospektweb\\PropModificator\\ValidationRules'  => 'lib/ValidationRules.php',
    'Prospektweb\\PropModificator\\Domain\\Offer\\EnumValueResolver' => 'lib/Domain/Offer/EnumValueResolver.php',
    'Prospektweb\\PropModificator\\Domain\\Config\\ProductConfigReader' => 'lib/Domain/Config/ProductConfigReader.php',
    'Prospektweb\\PropModificator\\Domain\\DTO\\CalcPriceRequest' => 'lib/Domain/DTO/CalcPriceRequest.php',
    'Prospektweb\\PropModificator\\Domain\\DTO\\CalcPriceResult' => 'lib/Domain/DTO/CalcPriceResult.php',
    'Prospektweb\\PropModificator\\Domain\\DTO\\BasketCalcData' => 'lib/Domain/DTO/BasketCalcData.php',
    'Prospektweb\\PropModificator\\Infrastructure\\Http\\RequestInput' => 'lib/Infrastructure/Http/RequestInput.php',
    'Prospektweb\\PropModificator\\Infrastructure\\Http\\SessionStorage' => 'lib/Infrastructure/Http/SessionStorage.php',
    'Prospektweb\\PropModificator\\Infrastructure\\Http\\ServerContext' => 'lib/Infrastructure/Http/ServerContext.php',
    'Prospektweb\\PropModificator\\Infrastructure\\Bitrix\\OfferRepository' => 'lib/Infrastructure/Bitrix/OfferRepository.php',
    'Prospektweb\\PropModificator\\Infrastructure\\Bitrix\\CatalogRepository' => 'lib/Infrastructure/Bitrix/CatalogRepository.php',
    'Prospektweb\\PropModificator\\Infrastructure\\Bitrix\\ProductConfigRepository' => 'lib/Infrastructure/Bitrix/ProductConfigRepository.php',
    'Prospektweb\\PropModificator\\PriceInterpolator'  => 'lib/PriceInterpolator.php',
    'Prospektweb\\PropModificator\\BasketHandler'      => 'lib/BasketHandler.php',
    'Prospektweb\\PropModificator\\PageHandler'        => 'lib/PageHandler.php',
    'Prospektweb\\PropModificator\\AjaxController'     => 'lib/AjaxController.php',
    'Prospektweb\\PropModificator\\CustomConfig'       => 'lib/CustomConfig.php',
    'Prospektweb\\PropModificator\\AdminHandler'       => 'lib/AdminHandler.php',
    'Prospektweb\\PropModificator\\ProductResolver'    => 'lib/ProductResolver.php',
    'Prospektweb\\PropModificator\\OfferDataProvider'  => 'lib/OfferDataProvider.php',
    'Prospektweb\\PropModificator\\FrontendConfigBuilder' => 'lib/FrontendConfigBuilder.php',
    'Prospektweb\\PropModificator\\TemplateBootstrap'  => 'lib/TemplateBootstrap.php',
    'Prospektweb\\PropModificator\\OfferRepository'    => 'lib/OfferRepository.php',
    'Prospektweb\\PropModificator\\RequestParser'      => 'lib/RequestParser.php',
    'Prospektweb\\PropModificator\\PricingService'     => 'lib/PricingService.php',
    'Prospektweb\\PropModificator\\MainPriceResolver'  => 'lib/MainPriceResolver.php',
    'Prospektweb\\PropModificator\\ResponseFactory'    => 'lib/ResponseFactory.php',
]);
