<?php

namespace Prospektweb\PropModificator;

/**
 * Resolves single primary price from raw prices and quantity ranges.
 *
 * Input: calculated prices, price groups metadata and UI hints.
 * Output: ['groupId' => int, 'price' => float] or null.
 */
class MainPriceResolver
{
    public function resolve(array $rawPrices, array $rangePrices, array $catalogGroups, array $accessibleGroupIds, int $basketQty, array $visibleGroups, ?int $activeGroupId): ?array
    {
        if (!empty($rangePrices)) {
            $best = null;
            foreach ($rangePrices as $gid => $rows) {
                if (!in_array((int)$gid, $accessibleGroupIds, true)) {
                    continue;
                }
                if (!empty($visibleGroups) && !in_array((int)$gid, $visibleGroups, true)) {
                    continue;
                }
                $picked = $this->pickRange($rows, $basketQty);
                if (!$picked) {
                    continue;
                }
                $cand = ['groupId' => (int)$gid, 'price' => (float)$picked['price']];
                if ($activeGroupId !== null && (int)$gid === $activeGroupId) {
                    return $cand;
                }
                if ($best === null || $cand['price'] < $best['price']) {
                    $best = $cand;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        $best = null;
        foreach ($rawPrices as $gid => $price) {
            if (!in_array((int)$gid, $accessibleGroupIds, true)) {
                continue;
            }
            if ($activeGroupId !== null && (int)$gid === $activeGroupId) {
                return ['groupId' => (int)$gid, 'price' => (float)$price];
            }
            if (!empty($visibleGroups) && !in_array((int)$gid, $visibleGroups, true)) {
                continue;
            }
            if ($best === null || (float)$price < $best['price']) {
                $best = ['groupId' => (int)$gid, 'price' => (float)$price];
            }
        }

        return $best;
    }

    private function pickRange(array $rows, int $basketQty): ?array
    {
        foreach ($rows as $row) {
            $from = $row['from'];
            $to = $row['to'];
            $okFrom = $from === null || $basketQty >= (int)$from;
            $okTo = $to === null || $basketQty <= (int)$to;
            if ($okFrom && $okTo) {
                return $row;
            }
        }
        return $rows[0] ?? null;
    }
}
