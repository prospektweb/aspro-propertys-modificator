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
        $hasAccessFilter = !empty($accessibleGroupIds);
        $isAccessible = static function (int $gid) use ($accessibleGroupIds, $hasAccessFilter): bool {
            return !$hasAccessFilter || in_array($gid, $accessibleGroupIds, true);
        };

        if (!empty($rangePrices)) {
            if ($activeGroupId !== null && $isAccessible((int)$activeGroupId) && isset($rangePrices[$activeGroupId])) {
                $picked = $this->pickRange((array)$rangePrices[$activeGroupId], $basketQty);
                if ($picked) {
                    return ['groupId' => (int)$activeGroupId, 'price' => (float)$picked['price']];
                }
            }

            if (!empty($visibleGroups)) {
                foreach ($visibleGroups as $visibleGid) {
                    $gid = (int)$visibleGid;
                    if (!$isAccessible($gid)) {
                        continue;
                    }
                    if (!isset($rangePrices[$gid])) {
                        continue;
                    }
                    $picked = $this->pickRange((array)$rangePrices[$gid], $basketQty);
                    if ($picked) {
                        return ['groupId' => $gid, 'price' => (float)$picked['price']];
                    }
                }
            }

            $best = null;
            foreach ($rangePrices as $gid => $rows) {
                if (!$isAccessible((int)$gid)) {
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
                if ($best === null || $cand['price'] < $best['price']) {
                    $best = $cand;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        $best = null;
        if ($activeGroupId !== null && $isAccessible((int)$activeGroupId) && isset($rawPrices[$activeGroupId])) {
            return ['groupId' => (int)$activeGroupId, 'price' => (float)$rawPrices[$activeGroupId]];
        }
        if (!empty($visibleGroups)) {
            foreach ($visibleGroups as $visibleGid) {
                $gid = (int)$visibleGid;
                if (!$isAccessible($gid)) {
                    continue;
                }
                if (!isset($rawPrices[$gid])) {
                    continue;
                }
                return ['groupId' => $gid, 'price' => (float)$rawPrices[$gid]];
            }
        }

        foreach ($rawPrices as $gid => $price) {
            if (!$isAccessible((int)$gid)) {
                continue;
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
