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
        $visibleOrder = [];
        foreach ($visibleGroups as $idx => $visibleGid) {
            $visibleOrder[(int)$visibleGid] = $idx;
        }

        if (!empty($rangePrices)) {
            $candidates = [];
            foreach ($rangePrices as $gid => $rows) {
                $gid = (int)$gid;
                if (!empty($visibleGroups) && !in_array($gid, $visibleGroups, true)) {
                    continue;
                }
                $picked = $this->pickRange($rows, $basketQty);
                if (!$picked) {
                    continue;
                }
                $candidates[] = [
                    'groupId' => $gid,
                    'price' => (float)$picked['price'],
                    'canBuy' => $isAccessible($gid),
                    'active' => $activeGroupId !== null && $gid === (int)$activeGroupId,
                    'visibleOrder' => $visibleOrder[$gid] ?? PHP_INT_MAX,
                    'base' => !empty($catalogGroups[$gid]['base']),
                ];
            }
            return $this->pickBestCandidate($candidates);
        }

        $candidates = [];
        foreach ($rawPrices as $gid => $price) {
            $gid = (int)$gid;
            $price = (float)$price;
            if (!empty($visibleGroups) && !in_array($gid, $visibleGroups, true)) {
                continue;
            }
            $candidates[] = [
                'groupId' => $gid,
                'price' => $price,
                'canBuy' => $isAccessible($gid),
                'active' => $activeGroupId !== null && $gid === (int)$activeGroupId,
                'visibleOrder' => $visibleOrder[$gid] ?? PHP_INT_MAX,
                'base' => !empty($catalogGroups[$gid]['base']),
            ];
        }

        return $this->pickBestCandidate($candidates);
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

    private function pickBestCandidate(array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }

        $buyable = array_values(array_filter($candidates, static fn(array $cand): bool => (bool)$cand['canBuy']));
        $pool = !empty($buyable) ? $buyable : $candidates;

        usort($pool, static function (array $a, array $b): int {
            if ($a['price'] !== $b['price']) {
                return $a['price'] <=> $b['price'];
            }
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }
            if ($a['visibleOrder'] !== $b['visibleOrder']) {
                return $a['visibleOrder'] <=> $b['visibleOrder'];
            }
            if ($a['base'] !== $b['base']) {
                return $a['base'] ? -1 : 1;
            }
            return $a['groupId'] <=> $b['groupId'];
        });

        $best = $pool[0];
        return ['groupId' => (int)$best['groupId'], 'price' => (float)$best['price']];
    }
}
