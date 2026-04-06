<?php

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Domain\DTO\BasketCalcData;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Infrastructure\Http\RequestInput;
use Prospektweb\PropModificator\Infrastructure\Http\SessionStorage;

class BasketHandler
{
    private const SESSION_KEY = 'PMOD_CALC';

    public function __construct(
        private ?ProductConfigReader $productConfigReader = null,
        private ?PricingService $pricingService = null,
        private ?MainPriceResolver $mainPriceResolver = null,
    ) {
        $this->productConfigReader = $this->productConfigReader ?? new ProductConfigReader();
        $this->pricingService = $this->pricingService ?? new PricingService();
        $this->mainPriceResolver = $this->mainPriceResolver ?? new MainPriceResolver();
    }

    public static function onBeforeBasketAdd(array &$arFields): ?bool
    {
        return (new self())->handleBeforeBasketAdd($arFields, RequestInput::fromGlobals(), SessionStorage::fromGlobals());
    }

    public static function onBeforeSaleBasketItemSetFields($basketItem): void
    {
        (new self())->handleBeforeSaleBasketItemSetFields($basketItem, SessionStorage::fromGlobals());
    }

    public function handleBeforeBasketAdd(array &$arFields, RequestInput $input, SessionStorage $session): ?bool
    {
        $calcData = $this->getCalcDataFromRequest($input);

        if ($calcData === null || $calcData->isCustom !== 'Y') {
            return true;
        }

        if (!$this->validateCalcData($calcData, $arFields)) {
            return false;
        }

        $serverPrice = $this->recalculatePrice($calcData, $arFields);
        if ($serverPrice === null) {
            return false;
        }

        $arFields['PRICE'] = $serverPrice;
        $arFields['CUSTOM_PRICE'] = 'Y';

        $session->set(self::SESSION_KEY, $calcData->withServerPrice($serverPrice)->toArray());

        return true;
    }

    public function handleBeforeSaleBasketItemSetFields($basketItem, SessionStorage $session): void
    {
        $stored = $session->pull(self::SESSION_KEY);
        if (!is_array($stored)) {
            return;
        }

        $calcData = BasketCalcData::fromArray($stored);
        if ($calcData->isCustom !== 'Y') {
            return;
        }

        $props = $this->buildBasketProperties($calcData);

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

    private function getCalcDataFromRequest(RequestInput $input): ?BasketCalcData
    {
        $raw = $input->post('prospekt_calc');
        if (!is_array($raw)) {
            return null;
        }

        return BasketCalcData::fromArray($raw);
    }

    private function validateCalcData(BasketCalcData $calcData, array $arFields): bool
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $productId = (int)($arFields['PRODUCT_ID'] ?? $calcData->productId ?? 0);
        if (!$productId) {
            return false;
        }

        if (!ValidationRules::hasCustomInput($calcData->width, $calcData->height, $calcData->volume)) {
            return false;
        }

        $settings = $this->productConfigReader->readByProductId($productId);

        return ValidationRules::validateInput(
            $calcData->width,
            $calcData->height,
            $calcData->volume,
            $settings['formatSettings'] ?? [],
            $settings['volumeSettings'] ?? []
        );
    }

    private function recalculatePrice(BasketCalcData $calcData, array $arFields): ?float
    {
        $productId = (int)($arFields['PRODUCT_ID'] ?? $calcData->productId ?? 0);
        if (!$productId) {
            return null;
        }

        $request = new CalcPriceRequest(
            $productId,
            $calcData->volume,
            $calcData->width,
            $calcData->height,
            1,
            [],
            null,
            $calcData->otherProps,
            false,
        );

        $pricing = $this->pricingService->calculateDto($request);
        if (!$pricing->ok) {
            return null;
        }

        $mainPrice = $this->mainPriceResolver->resolve(
            $pricing->rawPrices,
            $pricing->rangePrices,
            $pricing->catalogGroups,
            $pricing->accessibleGroupIds,
            1,
            [],
            null
        );

        return $mainPrice ? (float)$mainPrice['price'] : null;
    }

    private function buildBasketProperties(BasketCalcData $calcData): array
    {
        $props = [];

        if ($calcData->width !== null && $calcData->height !== null) {
            $props[] = [
                'NAME' => 'Формат (Ш×В)',
                'VALUE' => $calcData->width . '×' . $calcData->height . ' мм',
                'CODE' => 'PMOD_FORMAT',
            ];
        }

        if ($calcData->volume !== null) {
            $props[] = [
                'NAME' => 'Тираж',
                'VALUE' => number_format($calcData->volume, 0, '.', ' ') . ' шт.',
                'CODE' => 'PMOD_VOLUME',
            ];
        }

        if ($calcData->serverPrice !== null) {
            $props[] = [
                'NAME' => 'Расчётная цена',
                'VALUE' => number_format((float)$calcData->serverPrice, 2, '.', ' ') . ' руб.',
                'CODE' => 'PMOD_PRICE',
            ];
        }

        return $props;
    }
}
