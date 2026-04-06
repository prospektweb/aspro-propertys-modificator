<?php

use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Infrastructure\Bitrix\BitrixPropertyBindingResolver;
use Prospektweb\PropModificator\OfferDataProvider;
use Prospektweb\PropModificator\TemplateBootstrap;

$productConfigReader = new ProductConfigReader();
$propertyBindingResolver = new BitrixPropertyBindingResolver();
$offerDataProvider = new OfferDataProvider($propertyBindingResolver, $productConfigReader);

(new TemplateBootstrap($offerDataProvider))->run();
