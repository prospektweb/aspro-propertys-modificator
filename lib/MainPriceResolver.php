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
            // 0) Явно активная группа из фронта (визуально выбранная в Aspro) — максимальный приоритет.
            if ($activeGroupId !== null && isset($rangePrices[$activeGroupId])) {
                $picked = $this->pickRange((array)$rangePrices[$activeGroupId], $basketQty);
                if ($picked) {
                    return ['groupId' => (int)$activeGroupId, 'price' => (float)$picked['price']];
                }
            }

            // 1) Порядок видимых групп (как в popup Aspro):
            //    1.1 первая buyable; 1.2 если buyable нет — первая с ценой.
            if (!empty($visibleGroups)) {
                foreach ($visibleGroups as $visibleGid) {
                    $gid = (int)$visibleGid;
                    if (!isset($rangePrices[$gid]) || !$isAccessible($gid)) {
                        continue;
                    }
                    $picked = $this->pickRange((array)$rangePrices[$gid], $basketQty);
                    if ($picked) {
                        return ['groupId' => $gid, 'price' => (float)$picked['price']];
                    }
                }

                foreach ($visibleGroups as $visibleGid) {
                    $gid = (int)$visibleGid;
                    if (!isset($rangePrices[$gid])) {
                        continue;
                    }
                    $picked = $this->pickRange((array)$rangePrices[$gid], $basketQty);
                    if ($picked) {
                        return ['groupId' => $gid, 'price' => (float)$picked['price']];
                    }
                }
            }

            // 2) Глобальный fallback по всем группам:
            //    2.1 минимальная buyable; 2.2 если buyable нет — минимальная из всех.
            $best = null;
            $bestAny = null;
            foreach ($rangePrices as $gid => $rows) {
                $gid = (int)$gid;
                if (!empty($visibleGroups) && !in_array($gid, $visibleGroups, true)) {
                    continue;
                }
                $picked = $this->pickRange($rows, $basketQty);
                if (!$picked) {
                    continue;
                }
                $cand = ['groupId' => $gid, 'price' => (float)$picked['price']];
                if ($bestAny === null || $cand['price'] < $bestAny['price']) {
                    $bestAny = $cand;
                }
                if (!$isAccessible($gid)) {
                    continue;
                }
                if ($best === null || $cand['price'] < $best['price']) {
                    $best = $cand;
                }
            }
            if ($best !== null) {
                return $best;
            }
            if ($bestAny !== null) {
                return $bestAny;
            }
        }

        $best = null;
        $bestAny = null;

        // 0) Явно активная группа — приоритет.
        if ($activeGroupId !== null && isset($rawPrices[$activeGroupId])) {
            return ['groupId' => (int)$activeGroupId, 'price' => (float)$rawPrices[$activeGroupId]];
        }

        // 1) Порядок видимых групп (как в Aspro): сначала buyable, затем любая с ценой.
        if (!empty($visibleGroups)) {
            foreach ($visibleGroups as $visibleGid) {
                $gid = (int)$visibleGid;
                if (!isset($rawPrices[$gid]) || !$isAccessible($gid)) {
                    continue;
                }
                return ['groupId' => $gid, 'price' => (float)$rawPrices[$gid]];
            }
            foreach ($visibleGroups as $visibleGid) {
                $gid = (int)$visibleGid;
                if (!isset($rawPrices[$gid])) {
                    continue;
                }
                return ['groupId' => $gid, 'price' => (float)$rawPrices[$gid]];
            }
        }

        // 2) Глобальный fallback: минимальная buyable; если buyable нет — минимальная из всех.
        foreach ($rawPrices as $gid => $price) {
            $gid = (int)$gid;
            $price = (float)$price;
            if (!empty($visibleGroups) && !in_array($gid, $visibleGroups, true)) {
                continue;
            }
            if ($bestAny === null || $price < $bestAny['price']) {
                $bestAny = ['groupId' => $gid, 'price' => $price];
            }
            if (!$isAccessible($gid)) {
                continue;
            }
            if ($best === null || $price < $best['price']) {
                $best = ['groupId' => $gid, 'price' => $price];
            }
        }

        return $best ?? $bestAny;
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
