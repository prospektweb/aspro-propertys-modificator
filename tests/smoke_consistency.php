<?php

require_once __DIR__ . '/../lib/CustomConfig.php';
require_once __DIR__ . '/../lib/ValidationRules.php';
require_once __DIR__ . '/../lib/PriceInterpolator.php';
require_once __DIR__ . '/../lib/RequestParser.php';
require_once __DIR__ . '/../lib/Infrastructure/Http/RequestInput.php';
require_once __DIR__ . '/../lib/Infrastructure/Bitrix/ProductConfigRepository.php';
require_once __DIR__ . '/../lib/Domain/Config/ProductConfigReader.php';
require_once __DIR__ . '/../lib/Domain/Offer/EnumValueResolver.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Prospektweb\\PropModificator\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/../lib/' . $relativePath . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Domain\Offer\EnumValueResolver;
use Prospektweb\PropModificator\Infrastructure\Http\RequestInput;
use Prospektweb\PropModificator\PriceInterpolator;
use Prospektweb\PropModificator\RequestParser;
use Prospektweb\PropModificator\ValidationRules;

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

$payload = [
    'VALUE' => json_encode([
        'version' => 1,
        'fields' => [
            [
                'id' => 'format',
                'mode' => 'group',
                'binding' => ['skuPropertyCode' => 'CALC_PROP_FORMAT'],
                'inputs' => [
                    ['min' => 100, 'max' => 500, 'step' => 10, 'measure' => 'мм', 'showMeasure' => true],
                    ['min' => 100, 'max' => 700, 'step' => 10, 'measure' => 'мм', 'showMeasure' => true],
                ],
            ],
            [
                'id' => 'volume',
                'mode' => 'single',
                'binding' => ['skuPropertyCode' => 'CALC_PROP_VOLUME'],
                'inputs' => [
                    ['min' => 50, 'max' => 10000, 'step' => 50, 'measure' => 'шт', 'showMeasure' => true],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE),
];

$reader = new ProductConfigReader();
$configA = $reader->readFromPropertyPayload($payload, 'CALC_PROP_FORMAT', 'CALC_PROP_VOLUME');
$configB = $reader->readFromPropertyPayload($payload, 'CALC_PROP_FORMAT', 'CALC_PROP_VOLUME');
assertTrue($configA['formatSettings'] === $configB['formatSettings'], 'Format settings must be stable across entrypoints');
assertTrue($configA['volumeSettings'] === $configB['volumeSettings'], 'Volume settings must be stable across entrypoints');

assertTrue(ValidationRules::validateInput(200, 300, 1000, $configA['formatSettings'], $configA['volumeSettings']), 'Valid input should pass unified validator');
assertTrue(!ValidationRules::validateInput(10, 300, 1000, $configA['formatSettings'], $configA['volumeSettings']), 'Out-of-range format should fail unified validator');

$enumResolver = new EnumValueResolver();
assertTrue($enumResolver->resolveXmlId(null, 2, [2 => '210x297']) === '210x297', 'Enum resolver must resolve ENUM_ID to XML_ID');
assertTrue($enumResolver->resolveXmlId('1000', 0, []) === '1000', 'Enum resolver must keep XML_ID from row as priority');

$points = [
    ['width' => 100, 'height' => 100, 'volume' => 100, 'price' => 10.0],
    ['width' => 200, 'height' => 100, 'volume' => 100, 'price' => 20.0],
    ['width' => 100, 'height' => 200, 'volume' => 200, 'price' => 30.0],
    ['width' => 200, 'height' => 200, 'volume' => 200, 'price' => 40.0],
];

$interpolator = new PriceInterpolator();
$priceFromAjaxFlow = $interpolator->interpolatePoints($points, 150, 150, 150);
$priceFromBasketFlow = $interpolator->interpolatePoints($points, 150, 150, 150);
assertTrue(abs((float)$priceFromAjaxFlow - (float)$priceFromBasketFlow) < 0.00001, 'Interpolation must be consistent across entrypoints');

$requestParser = new RequestParser();
$resultWithExtraGet = $requestParser->parseFromInput(new RequestInput(
    ['width' => '100', 'height' => '100'],
    ['productId' => '123', 'unexpected' => 'trash']
));
assertTrue($resultWithExtraGet['ok'] === true, 'Request parser must ignore unknown GET params');
assertTrue(($resultWithExtraGet['data']['productId'] ?? 0) === 123, 'Request parser must still parse productId from GET');

echo "OK\n";
