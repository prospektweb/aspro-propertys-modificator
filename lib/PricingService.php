<?php

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceResult;
use Prospektweb\PropModificator\Infrastructure\Bitrix\CatalogRepository;
use Prospektweb\PropModificator\Infrastructure\Bitrix\OfferRepository;

class PricingService
{
    public function __construct(
        private ?ProductConfigReader $productConfigReader = null,
        private ?OfferRepository $offerRepository = null,
        private ?CatalogRepository $catalogRepository = null,
        private ?PriceInterpolator $interpolator = null,
    ) {
        $this->productConfigReader = $this->productConfigReader ?? new ProductConfigReader();
        $this->offerRepository = $this->offerRepository ?? new OfferRepository();
        $this->catalogRepository = $this->catalogRepository ?? new CatalogRepository();
        $this->interpolator = $this->interpolator ?? new PriceInterpolator();
    }

    /** @param array<string,mixed>|CalcPriceRequest $request */
    public function calculate(array|CalcPriceRequest $request): array
    {
        $dto = $request instanceof CalcPriceRequest ? $request : CalcPriceRequest::fromArray($request);
        return $this->calculateDto($dto)->toArray();
    }

    public function calculateDto(CalcPriceRequest $request): CalcPriceResult
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return new CalcPriceResult(false, 'Required modules not loaded', [], [], [], [], []);
        }

        if (!ValidationRules::hasCustomInput($request->width, $request->height, $request->volume)) {
            return new CalcPriceResult(false, 'At least one of volume, width+height required', [], [], [], [], []);
        }

        $settings = $this->productConfigReader->readByProductId($request->productId);
        if (!ValidationRules::validateInput(
            $request->width,
            $request->height,
            $request->volume,
            $settings['formatSettings'] ?? [],
            $settings['volumeSettings'] ?? []
        )) {
            return new CalcPriceResult(false, 'Input is out of configured limits', [], [], [], [], []);
        }

        $meta = $this->offerRepository->loadOfferMetadata($request->productId, $request->otherProps);
        if (empty($meta)) {
            return new CalcPriceResult(false, 'No prices could be calculated', [], [], [], [], []);
        }

        $offerIds = array_keys($meta);

        $pricesByGroup = $this->offerRepository->loadGroupPrices($offerIds);
        $rangeRowsByGroup = $this->offerRepository->loadGroupRangePrices($offerIds);

        $rawPrices = [];
        foreach ($pricesByGroup as $gid => $groupPrices) {
            $points = [];
            foreach ($meta as $oid => $m) {
                $price = $groupPrices[$oid] ?? null;
                if ($price === null || $price <= 0 || ($m['volume'] ?? null) === null) {
                    continue;
                }
                $points[] = array_merge($m, ['price' => $price]);
            }
            $p = $this->interpolator->interpolatePoints($points, $request->width, $request->height, $request->volume);
            if ($p !== null) {
                $rawPrices[(int)$gid] = $p;
            }
        }

        $rangePrices = [];
        foreach ($rangeRowsByGroup as $gid => $rangeMap) {
            foreach ($rangeMap as $rangeRows) {
                if (empty($rangeRows)) {
                    continue;
                }
                $from = $rangeRows[0]['from'];
                $to = $rangeRows[0]['to'];
                $points = [];
                foreach ($rangeRows as $row) {
                    $oid = (int)$row['offerId'];
                    if (!isset($meta[$oid])) {
                        continue;
                    }
                    $m = $meta[$oid];
                    if (($m['volume'] ?? null) === null || $row['price'] <= 0) {
                        continue;
                    }
                    $points[] = array_merge($m, ['price' => (float)$row['price']]);
                }
                $p = $this->interpolator->interpolatePoints($points, $request->width, $request->height, $request->volume);
                if ($p !== null) {
                    $rangePrices[(int)$gid][] = ['from' => $from, 'to' => $to, 'price' => $p];
                }
            }
        }

        $catalogGroups = $this->catalogRepository->loadCatalogGroups();
        $roundingRules = $this->catalogRepository->loadRoundingRules();

        foreach ($rawPrices as $gid => &$price) {
            if (!empty($roundingRules[$gid])) {
                $price = $this->applyRounding((float)$price, $roundingRules[$gid]);
            }
        }
        unset($price);

        foreach ($rangePrices as $gid => &$rows) {
            if (empty($roundingRules[$gid])) {
                continue;
            }
            foreach ($rows as &$row) {
                $row['price'] = $this->applyRounding((float)$row['price'], $roundingRules[$gid]);
            }
            unset($row);
        }
        unset($rows);

        $accessibleGroupIds = $this->catalogRepository->getAccessiblePriceGroups();

        $ok = !empty($rawPrices) || !empty($rangePrices);
        return new CalcPriceResult(
            $ok,
            $ok ? null : 'No prices could be calculated',
            $rawPrices,
            $rangePrices,
            $catalogGroups,
            $roundingRules,
            $accessibleGroupIds,
        );
    }

    private function applyRounding(float $price, array $rules): float
    {
        $applied = null;
        foreach ($rules as $rule) {
            if ($price >= (float)$rule['price']) {
                $applied = $rule;
            }
        }
        if (!$applied) {
            return $price;
        }
        $precision = max((float)$applied['precision'], 0.000001);

        return match ((int)$applied['type']) {
            1 => floor($price / $precision) * $precision,
            2 => round($price / $precision) * $precision,
            3 => ceil($price / $precision) * $precision,
            default => $price,
        };
    }
}
