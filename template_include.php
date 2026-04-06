<?php

use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Domain\Offer\EnumValueResolver;
use Prospektweb\PropModificator\OfferDataProvider;
use Prospektweb\PropModificator\TemplateBootstrap;

$enumValueResolver = new EnumValueResolver();
$productConfigReader = new ProductConfigReader();
$offerDataProvider = new OfferDataProvider($enumValueResolver, $productConfigReader);

(new TemplateBootstrap($offerDataProvider))->run();
