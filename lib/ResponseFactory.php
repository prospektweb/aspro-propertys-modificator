<?php

namespace Prospektweb\PropModificator;

use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Domain\DTO\CalcPriceResult;

class ResponseFactory
{
    public function success(CalcPriceResult $pricing, ?array $mainPrice, CalcPriceRequest $request): array
    {
        $pricesResult = [];
        foreach ($pricing->rawPrices as $gid => $price) {
            $group = $pricing->catalogGroups[$gid] ?? null;
            $pricesResult[$gid] = [
                'raw' => round((float)$price, 2),
                'formatted' => $this->formatPrice((float)$price),
                'groupName' => $group ? (string)$group['name'] : '',
                'canBuy' => in_array((int)$gid, $pricing->accessibleGroupIds, true),
            ];
        }

        $result = [
            'success' => true,
            'prices' => $pricesResult,
            'ranges' => $pricing->rangePrices,
            'mainPrice' => $mainPrice ? ['raw' => round((float)$mainPrice['price'], 2), 'formatted' => $this->formatPrice((float)$mainPrice['price']), 'groupId' => (int)$mainPrice['groupId']] : null,
            'meta' => ['currency' => 'RUB', 'vatIncluded' => true, 'roundingApplied' => !empty($pricing->roundingRules)],
            'requestId' => uniqid('pmod_', true),
        ];

        if ($request->debug) {
            $result['debug'] = [
                'activeGroupId' => $request->activeGroupId,
                'visibleGroups' => $request->visibleGroups,
                'accessibleIds' => $pricing->accessibleGroupIds,
                'resolvedMain' => $mainPrice,
            ];
        }

        return $result;
    }

    public function error(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, fmod($price, 1.0) == 0.0 ? 0 : 2, '.', ' ') . ' ₽';
    }
}
