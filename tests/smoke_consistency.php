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

use Prospektweb\PropModificator\CustomConfig;
use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Domain\Offer\EnumValueResolver;
use Prospektweb\PropModificator\Infrastructure\Http\RequestInput;
use Prospektweb\PropModificator\OfferDataProvider;
use Prospektweb\PropModificator\PriceInterpolator;
use Prospektweb\PropModificator\ResponseFactory;
use Prospektweb\PropModificator\RequestParser;
use Prospektweb\PropModificator\ValidationRules;
use Prospektweb\PropModificator\MainPriceResolver;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceResult;

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

$defaultOfferDataProvider = new OfferDataProvider();
assertTrue($defaultOfferDataProvider instanceof OfferDataProvider, 'OfferDataProvider DI contract must allow default construction');

$templateIncludePath = __DIR__ . '/../template_include.php';
$templateIncludeCode = file_get_contents($templateIncludePath);
assertTrue(is_string($templateIncludeCode) && $templateIncludeCode !== '', 'template_include.php must be readable for wiring smoke checks');
assertTrue(
    preg_match('/new\s+OfferDataProvider\s*\([^)]*EnumValueResolver/s', $templateIncludeCode) !== 1,
    'template_include wiring must pass PropertyBindingResolverInterface or use defaults'
);

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
    ['productId' => '123', 'unexpected' => 'trash', 'pmod_volume' => '1000']
));
assertTrue($resultWithExtraGet['ok'] === true, 'Request parser must ignore unknown GET params');
assertTrue(($resultWithExtraGet['data']['productId'] ?? 0) === 123, 'Request parser must still parse productId from GET');

$resultWithStructuredExtraGet = $requestParser->parseFromInput(new RequestInput(
    ['width' => '100', 'height' => '100'],
    ['productId' => '124', 'tracking' => ['utm_source' => 'newsletter'], 'debug' => '1']
));
assertTrue($resultWithStructuredExtraGet['ok'] === true, 'Request parser must ignore nested/structured unknown GET params');
assertTrue(($resultWithStructuredExtraGet['data']['productId'] ?? 0) === 124, 'Request parser must keep parsing productId when GET contains nested data');

$invalidModeConfig = CustomConfig::parseFromPropertyValue([
    'VALUE' => json_encode([
        'version' => 1,
        'fields' => [
            ['id' => 'bad-unknown', 'mode' => 'matrix', 'inputs' => [['min' => 1]], 'binding' => ['skuPropertyCode' => 'CALC_PROP_FORMAT']],
            ['id' => 'bad-single', 'mode' => 'single', 'inputs' => [['min' => 1], ['min' => 2]], 'binding' => ['skuPropertyCode' => 'CALC_PROP_FORMAT']],
            ['id' => 'bad-group', 'mode' => 'group', 'inputs' => [[], [], [], [], []], 'binding' => ['skuPropertyCode' => 'CALC_PROP_FORMAT']],
            ['id' => 'ok-group', 'mode' => 'group', 'inputs' => [['min' => 100], ['min' => 200]], 'binding' => ['skuPropertyCode' => 'CALC_PROP_FORMAT']],
            ['id' => 'ok-single', 'mode' => 'single', 'inputs' => [['min' => 1000]], 'binding' => ['skuPropertyCode' => 'CALC_PROP_VOLUME']],
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$keptIds = array_column($invalidModeConfig['fields'] ?? [], 'id');
sort($keptIds);
assertTrue($keptIds === ['ok-group', 'ok-single'], 'CustomConfig must reject inconsistent mode handlers and keep only valid single/group definitions');

$ajaxEndpointPath = __DIR__ . '/../install/assets/ajax/prospektweb.propmodificator/calc_price.php';
$ajaxEndpointCode = file_get_contents($ajaxEndpointPath);
assertTrue(is_string($ajaxEndpointCode) && $ajaxEndpointCode !== '', 'AJAX endpoint file must be readable for smoke checks');
assertTrue(strpos($ajaxEndpointCode, 'check_bitrix_sessid') !== false, 'AJAX endpoint must enforce CSRF check (check_bitrix_sessid)');
assertTrue(strpos($ajaxEndpointCode, 'INVALID_SESSID') !== false, 'AJAX endpoint must return INVALID_SESSID on failed CSRF check');

$fieldModeHandlersPath = __DIR__ . '/../install/assets/js/prospektweb.propmodificator/pricing/field-mode-handlers.js';
$fieldModeHandlersCode = file_get_contents($fieldModeHandlersPath);
assertTrue(is_string($fieldModeHandlersCode) && $fieldModeHandlersCode !== '', 'Field mode handlers file must be readable');
assertTrue(strpos($fieldModeHandlersCode, "format: createFormatHandler()") !== false, 'Frontend mode handlers must expose format handler');
assertTrue(strpos($fieldModeHandlersCode, "volume: createVolumeHandler()") !== false, 'Frontend mode handlers must expose volume handler');
assertTrue(strpos($fieldModeHandlersCode, "skuPropertyCode: 'CALC_PROP_FORMAT'") !== false, 'Format handler must be bound to CALC_PROP_FORMAT');
assertTrue(strpos($fieldModeHandlersCode, "skuPropertyCode: 'CALC_PROP_VOLUME'") !== false, 'Volume handler must be bound to CALC_PROP_VOLUME');

$resolver = new MainPriceResolver();
$resolvedWithEmptyAccess = $resolver->resolve(
    [1 => 100.0, 2 => 90.0],
    [1 => [['from' => null, 'to' => null, 'price' => 100.0]], 2 => [['from' => null, 'to' => null, 'price' => 90.0]]],
    [1 => ['id' => 1, 'name' => 'BASE', 'base' => true], 2 => ['id' => 2, 'name' => 'OPT', 'base' => false]],
    [],
    1,
    [1, 2],
    1
);
assertTrue(is_array($resolvedWithEmptyAccess), 'MainPriceResolver must not return null when accessible groups are empty');
assertTrue((int)($resolvedWithEmptyAccess['groupId'] ?? 0) === 1, 'MainPriceResolver must honor preferred active group when access filter is empty');

$resolvedWithNoBuyableMatch = $resolver->resolve(
    [1 => 100.0, 2 => 90.0],
    [1 => [['from' => null, 'to' => null, 'price' => 100.0]], 2 => [['from' => null, 'to' => null, 'price' => 90.0]]],
    [1 => ['id' => 1, 'name' => 'BASE', 'base' => true], 2 => ['id' => 2, 'name' => 'OPT', 'base' => false]],
    [999], // фильтр задан, но не пересекается с ценовыми группами
    1,
    [1, 2],
    1
);
assertTrue(is_array($resolvedWithNoBuyableMatch), 'MainPriceResolver must fallback to visible/active group when buyable groups are absent');
assertTrue((int)($resolvedWithNoBuyableMatch['groupId'] ?? 0) === 1, 'MainPriceResolver must keep active group priority even without buyable matches');

$responseFactory = new ResponseFactory();
$requestDto = new CalcPriceRequest(1, 100, null, null, 1, [1, 2], 1, null, false);
$pricingDto = new CalcPriceResult(
    true,
    null,
    [1 => 100.0, 2 => 90.0],
    [],
    [1 => ['id' => 1, 'name' => 'BASE', 'base' => true], 2 => ['id' => 2, 'name' => 'OPT', 'base' => false]],
    [],
    []
);
$response = $responseFactory->success($pricingDto, ['groupId' => 1, 'price' => 100.0], $requestDto);
assertTrue(($response['prices'][1]['canBuy'] ?? false) === true, 'ResponseFactory must treat empty accessible groups as unrestricted for canBuy flag');
assertTrue(($response['prices'][2]['canBuy'] ?? false) === true, 'ResponseFactory must mark all groups as canBuy when access filter is empty');

echo "OK\n";
