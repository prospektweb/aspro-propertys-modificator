<?php
/**
 * AJAX-контроллер: пересчёт цены на произвольный тираж/формат.
 *
 * Принимает POST-параметры (см. ajax/calc_price.php):
 *   productId   — ID товара (родитель)
 *   volume      — тираж (int, опционально)
 *   width       — ширина мм (int, опционально)
 *   height      — высота мм (int, опционально)
 *   other_props — [propId => enumId] (опционально)
 *
 * Возвращает JSON:
 * {
 *   "success": true,
 *   "prices": {
 *     "1": {"raw": 12345.67, "formatted": "12 346 ₽", "groupName": "BASE", "canBuy": true}
 *   },
 *   "mainPrice": {"raw": 12345.67, "formatted": "12 346 ₽", "groupId": 1},
 *   "meta": {"currency": "RUB", "vatIncluded": true, "roundingApplied": false},
 *   "requestId": "pmod_..."
 * }
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\RoundingTable;

class AjaxController
{
    /**
     * Обрабатывает AJAX-запрос пересчёта цен.
     * Вызывается из ajax/calc_price.php.
     *
     * @return array
     */
    public static function calcPrice(): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return ['success' => false, 'error' => 'Required modules not loaded'];
        }

        // ── Входные параметры ─────────────────────────────────────────────────

        $productId = (int)($_POST['productId'] ?? $_GET['productId'] ?? 0);
        $volume    = isset($_POST['volume']) && $_POST['volume'] !== '' ? (int)$_POST['volume'] : null;
        $width     = isset($_POST['width']) && $_POST['width'] !== '' ? (int)$_POST['width'] : null;
        $height    = isset($_POST['height']) && $_POST['height'] !== '' ? (int)$_POST['height'] : null;
        $basketQty = isset($_POST['basket_qty']) && (int)$_POST['basket_qty'] > 0 ? (int)$_POST['basket_qty'] : 1;

        $visibleGroups = [];
        if (isset($_POST['visible_groups']) && is_array($_POST['visible_groups'])) {
            foreach ($_POST['visible_groups'] as $gidRaw) {
                $gid = (int)$gidRaw;
                if ($gid > 0) {
                    $visibleGroups[$gid] = $gid;
                }
            }
            $visibleGroups = array_values($visibleGroups);
        }

        $activeGroupId = isset($_POST['active_group_id']) && (int)$_POST['active_group_id'] > 0
            ? (int)$_POST['active_group_id']
            : null;

        $otherProps = isset($_POST['other_props']) && is_array($_POST['other_props'])
            ? array_map('intval', $_POST['other_props'])
            : null;

        if (!$productId) {
            return ['success' => false, 'error' => 'productId required'];
        }

        if ($volume === null && $width === null && $height === null) {
            return ['success' => false, 'error' => 'At least one of volume, width, height required'];
        }

        // ── Интерполяция цен по всем группам ─────────────────────────────────

        $interpolator = new PriceInterpolator($productId);
        $rawPrices    = $interpolator->interpolateAllGroups($width, $height, $volume, $otherProps);
        $rangePrices  = $interpolator->interpolateAllGroupsWithRanges($width, $height, $volume, $otherProps);

        if (!empty($rangePrices)) {
            $rawPrices = self::extractMainPricesFromRanges($rangePrices);
        }

        if (empty($rawPrices)) {
            return ['success' => false, 'error' => 'No prices could be calculated'];
        }

        // ── Загружаем метаданные типов цен и правила округления ───────────────

        $catalogGroups = self::loadCatalogGroups();
        $roundingRules = self::loadRoundingRules();

        // Применяем округление к обычным ценам
        foreach ($rawPrices as $gid => &$price) {
            if (!empty($roundingRules[$gid])) {
                $price = self::applyRounding((float)$price, $roundingRules[$gid]);
            }
        }
        unset($price);

        // Применяем округление к диапазонным ценам
        foreach ($rangePrices as $gid => &$rows) {
            if (empty($roundingRules[$gid]) || !is_array($rows)) {
                continue;
            }

            foreach ($rows as &$row) {
                if (isset($row['price'])) {
                    $row['price'] = self::applyRounding((float)$row['price'], $roundingRules[$gid]);
                }
            }
            unset($row);
        }
        unset($rows);

        // Если диапазоны есть, после округления актуализируем rawPrices из них,
        // чтобы fallback по rawPrices не расходился с ranges.
        if (!empty($rangePrices)) {
            $rawPrices = self::extractMainPricesFromRanges($rangePrices);
        }

        // ── Определяем доступность типов цен для текущего пользователя ────────

        $accessibleGroupIds = self::getAccessiblePriceGroups();

        // ── Формируем ответ ────────────────────────────────────────────────────

        $pricesResult = [];
        foreach ($rawPrices as $gid => $price) {
            $group = $catalogGroups[$gid] ?? null;

            $pricesResult[$gid] = [
                'raw'       => round((float)$price, 2),
                'formatted' => self::formatPrice((float)$price),
                'groupName' => $group ? (string)$group['name'] : '',
                'canBuy'    => in_array((int)$gid, $accessibleGroupIds, true),
            ];
        }

        $mainPrice = !empty($rangePrices)
            ? self::determineMainPriceFromRanges(
                $rangePrices,
                $accessibleGroupIds,
                $basketQty,
                $catalogGroups,
                $visibleGroups,
                $activeGroupId
            )
            : self::determineMainPrice(
                $rawPrices,
                $catalogGroups,
                $accessibleGroupIds,
                $activeGroupId
            );

        // Жёсткий fallback: если ranges есть, но mainPrice не резолвится — берём лучшую цену из ranges
        if ($mainPrice === null && !empty($rangePrices)) {
            $mainPrice = self::fallbackMainPriceFromRanges(
                $rangePrices,
                $basketQty,
                $accessibleGroupIds,
                $catalogGroups
            );
        }

        // Последний fallback: по rawPrices
        if ($mainPrice === null && !empty($rawPrices)) {
            $mainPrice = self::determineMainPrice(
                $rawPrices,
                $catalogGroups,
                $accessibleGroupIds,
                $activeGroupId
            );
        }

        $result = [
            'success'   => true,
            'prices'    => $pricesResult,
            'ranges'    => $rangePrices,
            'mainPrice' => $mainPrice !== null ? [
                'raw'       => round((float)$mainPrice['price'], 2),
                'formatted' => self::formatPrice((float)$mainPrice['price']),
                'groupId'   => (int)$mainPrice['groupId'],
            ] : null,
            'meta'      => [
                'currency'        => 'RUB',
                'vatIncluded'     => true,
                'roundingApplied' => !empty($roundingRules),
            ],
            'requestId' => uniqid('pmod_', true),
        ];

        if (isset($_POST['debug']) && $_POST['debug'] === 'Y') {
            $result['debug'] = [
                'activeGroupId' => $activeGroupId,
                'visibleGroups' => $visibleGroups,
                'accessibleIds' => $accessibleGroupIds,
                'resolvedMain'  => $mainPrice,
            ];
        }

        return $result;
    }

    // ── Вспомогательные методы ────────────────────────────────────────────────

    /**
     * Загружает информацию о типах цен.
     *
     * @return array [groupId => ['id' => int, 'name' => string, 'base' => bool]]
     */
    private static function loadCatalogGroups(): array
    {
        $groups = [];

        try {
            $rs = GroupTable::getList([
                'select' => ['ID', 'NAME', 'BASE'],
                'order'  => ['ID' => 'ASC'],
            ]);

            while ($ar = $rs->fetch()) {
                $gid = (int)$ar['ID'];

                $groups[$gid] = [
                    'id'   => $gid,
                    'name' => (string)$ar['NAME'],
                    'base' => (($ar['BASE'] ?? 'N') === 'Y'),
                ];
            }
        } catch (\Throwable $e) {
            // продолжаем без групп
        }

        return $groups;
    }

    /**
     * Загружает правила округления цен из Bitrix Catalog.
     *
     * @return array [groupId => [['price' => float, 'type' => int, 'precision' => float], ...]]
     */
    private static function loadRoundingRules(): array
    {
        $rules = [];

        try {
            $rs = RoundingTable::getList([
                'select' => ['CATALOG_GROUP_ID', 'PRICE', 'ROUND_TYPE', 'ROUND_PRECISION'],
                'order'  => ['CATALOG_GROUP_ID' => 'ASC', 'PRICE' => 'ASC'],
            ]);

            while ($ar = $rs->fetch()) {
                $gid = (int)$ar['CATALOG_GROUP_ID'];

                $rules[$gid][] = [
                    'price'     => (float)$ar['PRICE'],
                    'type'      => (int)$ar['ROUND_TYPE'],
                    'precision' => (float)$ar['ROUND_PRECISION'],
                ];
            }
        } catch (\Throwable $e) {
            // продолжаем без правил
        }

        return $rules;
    }

    /**
     * Применяет правила округления Bitrix к цене.
     *
     * @param float $price
     * @param array $rules
     * @return float
     */
    private static function applyRounding(float $price, array $rules): float
    {
        $rule = null;

        foreach ($rules as $r) {
            if ($price >= $r['price'] && ($rule === null || $r['price'] >= $rule['price'])) {
                $rule = $r;
            }
        }

        if ($rule === null) {
            return $price;
        }

        $precision = max((float)$rule['precision'], 0.01);

        switch ((int)$rule['type']) {
            case 1: // ROUND_DOWN
                return floor($price / $precision) * $precision;

            case 2: // ROUND_MATH
                return round($price / $precision) * $precision;

            case 3: // ROUND_UP
                return ceil($price / $precision) * $precision;

            default:
                return $price;
        }
    }

    /**
     * Возвращает список ID типов цен, доступных текущему пользователю для покупки.
     *
     * @return int[]
     */
    private static function getAccessiblePriceGroups(): array
    {
        global $USER;

        $userGroups = [];
        if (is_object($USER) && method_exists($USER, 'GetUserGroupArray')) {
            $userGroups = (array)$USER->GetUserGroupArray();
        }

        if (empty($userGroups)) {
            $userGroups = [2]; // гости
        }

        $canBuyGroupIds = [];

        if (Loader::includeModule('catalog') && class_exists('CCatalogGroup')) {
            try {
                $perms = \CCatalogGroup::GetGroupsPerms($userGroups);

                if (is_array($perms)) {
                    // Нормальная структура Bitrix:
                    // [
                    //   'view' => [1,2,3,4],
                    //   'buy'  => [1,4]
                    // ]
                    if (isset($perms['buy']) && is_array($perms['buy'])) {
                        foreach ($perms['buy'] as $gid) {
                            $gid = (int)$gid;
                            if ($gid > 0) {
                                $canBuyGroupIds[$gid] = $gid;
                            }
                        }
                    }
                    // Запасной вариант на случай нестандартной структуры
                    else {
                        foreach ($perms as $gid => $perm) {
                            if (is_array($perm) && isset($perm['buy']) && $perm['buy'] === 'Y') {
                                $gid = (int)$gid;
                                if ($gid > 0) {
                                    $canBuyGroupIds[$gid] = $gid;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // продолжаем без данных о правах
            }
        }

        $canBuyGroupIds = array_values($canBuyGroupIds);
        sort($canBuyGroupIds);

        return $canBuyGroupIds;
    }

    /**
     * Определяет главную цену для покупки по обычным ценам.
     *
     * Правило:
     * 1. Если передана preferredGroupId и она доступна для покупки — берём её.
     * 2. Если есть buyable-группы — берём минимальную цену среди них.
     * 3. Если buyable-групп нет — берём базовую группу.
     * 4. Иначе — минимальную цену среди всех доступных rawPrices.
     *
     * @param array    $rawPrices
     * @param array    $catalogGroups
     * @param array    $accessibleIds
     * @param int|null $preferredGroupId
     * @return array|null ['price' => float, 'groupId' => int]
     */
    private static function determineMainPrice(
        array $rawPrices,
        array $catalogGroups,
        array $accessibleIds,
        ?int $preferredGroupId = null
    ): ?array {
        if (empty($rawPrices)) {
            return null;
        }

        // 0. Предпочтительная активная группа — только если реально buyable
        if (
            $preferredGroupId !== null
            && isset($rawPrices[$preferredGroupId])
            && in_array((int)$preferredGroupId, $accessibleIds, true)
        ) {
            return [
                'price'   => (float)$rawPrices[$preferredGroupId],
                'groupId' => (int)$preferredGroupId,
            ];
        }

        $allCandidates = [];
        foreach ($rawPrices as $gid => $price) {
            $gid = (int)$gid;
            $allCandidates[] = [
                'groupId' => $gid,
                'price'   => (float)$price,
                'canBuy'  => in_array($gid, $accessibleIds, true),
                'base'    => !empty($catalogGroups[$gid]['base']),
                'ord'     => 0,
            ];
        }

        if (empty($allCandidates)) {
            return null;
        }

        // 1. Минимальная среди buyable
        $buyableCandidates = [];
        foreach ($allCandidates as $candidate) {
            if (!empty($candidate['canBuy'])) {
                $buyableCandidates[] = $candidate;
            }
        }

        if (!empty($buyableCandidates)) {
            usort($buyableCandidates, [self::class, 'compareMainPriceCandidates']);
            return [
                'price'   => (float)$buyableCandidates[0]['price'],
                'groupId' => (int)$buyableCandidates[0]['groupId'],
            ];
        }

        // 2. Базовая группа
        $baseCandidates = [];
        foreach ($allCandidates as $candidate) {
            if (!empty($candidate['base'])) {
                $baseCandidates[] = $candidate;
            }
        }

        if (!empty($baseCandidates)) {
            usort($baseCandidates, [self::class, 'compareMainPriceCandidates']);
            return [
                'price'   => (float)$baseCandidates[0]['price'],
                'groupId' => (int)$baseCandidates[0]['groupId'],
            ];
        }

        // 3. Минимальная среди всех
        usort($allCandidates, [self::class, 'compareMainPriceCandidates']);

        return [
            'price'   => (float)$allCandidates[0]['price'],
            'groupId' => (int)$allCandidates[0]['groupId'],
        ];
    }

    /**
     * Определяет главную цену по диапазонам.
     *
     * Бизнес-правило:
     * - visibleGroups не участвует в выборе mainPrice;
     * - если buyable-группа одна — берём её цену по basketQty;
     * - если buyable-групп несколько — берём минимальную цену среди них;
     * - если buyable-групп нет — fallback на base;
     * - если base нет — минимальная среди всех валидных диапазонов.
     *
     * @param array    $rangePrices
     * @param array    $accessibleIds
     * @param int      $basketQty
     * @param array    $catalogGroups
     * @param array    $visibleGroups
     * @param int|null $preferredGroupId
     * @return array|null ['price' => float, 'groupId' => int]
     */
    private static function determineMainPriceFromRanges(
        array $rangePrices,
        array $accessibleIds,
        int $basketQty,
        array $catalogGroups,
        array $visibleGroups = [],
        ?int $preferredGroupId = null
    ): ?array {
        $rowsByGroup = [];

        foreach ($rangePrices as $gid => $rows) {
            $gid = (int)$gid;
            if (is_array($rows) && !empty($rows)) {
                $rowsByGroup[$gid] = $rows;
            }
        }

        if (empty($rowsByGroup)) {
            return null;
        }

        // 0. Предпочтительная активная группа — только если реально доступна для покупки
        if (
            $preferredGroupId !== null
            && !empty($rowsByGroup[$preferredGroupId])
            && in_array((int)$preferredGroupId, $accessibleIds, true)
        ) {
            $selectedPref = self::pickRangeForBasketQty($rowsByGroup[$preferredGroupId], $basketQty);
            if ($selectedPref !== null && isset($selectedPref['price'])) {
                return [
                    'price'   => (float)$selectedPref['price'],
                    'groupId' => (int)$preferredGroupId,
                ];
            }
        }

        // visibleGroups не используется для бизнес-выбора mainPrice
        $allCandidates = [];

        foreach ($rowsByGroup as $gid => $rows) {
            $selected = self::pickRangeForBasketQty($rows, $basketQty);
            if ($selected === null || !isset($selected['price'])) {
                continue;
            }

            $allCandidates[] = [
                'groupId' => (int)$gid,
                'price'   => (float)$selected['price'],
                'canBuy'  => in_array((int)$gid, $accessibleIds, true),
                'base'    => !empty($catalogGroups[$gid]['base']),
                'ord'     => 0,
            ];
        }

        if (empty($allCandidates)) {
            return null;
        }

        // 1. Минимальная среди buyable
        $buyableCandidates = [];
        foreach ($allCandidates as $candidate) {
            if (!empty($candidate['canBuy'])) {
                $buyableCandidates[] = $candidate;
            }
        }

        if (!empty($buyableCandidates)) {
            usort($buyableCandidates, [self::class, 'compareMainPriceCandidates']);
            return [
                'price'   => (float)$buyableCandidates[0]['price'],
                'groupId' => (int)$buyableCandidates[0]['groupId'],
            ];
        }

        // 2. Базовая группа
        $baseCandidates = [];
        foreach ($allCandidates as $candidate) {
            if (!empty($candidate['base'])) {
                $baseCandidates[] = $candidate;
            }
        }

        if (!empty($baseCandidates)) {
            usort($baseCandidates, [self::class, 'compareMainPriceCandidates']);
            return [
                'price'   => (float)$baseCandidates[0]['price'],
                'groupId' => (int)$baseCandidates[0]['groupId'],
            ];
        }

        // 3. Минимальная среди всех
        usort($allCandidates, [self::class, 'compareMainPriceCandidates']);

        return [
            'price'   => (float)$allCandidates[0]['price'],
            'groupId' => (int)$allCandidates[0]['groupId'],
        ];
    }

    /**
     * Сравнение кандидатов главной цены:
     * сначала по минимальной цене,
     * затем по base,
     * затем по groupId.
     */
    private static function compareMainPriceCandidates(array $a, array $b): int
    {
        if ($a['price'] === $b['price']) {
            if (!empty($a['base']) !== !empty($b['base'])) {
                return !empty($a['base']) ? -1 : 1;
            }

            return (int)$a['groupId'] <=> (int)$b['groupId'];
        }

        return (float)$a['price'] <=> (float)$b['price'];
    }

    /**
     * Жёсткий fallback главной цены по диапазонам.
     *
     * Правило:
     * 1. минимальная среди buyable;
     * 2. затем base;
     * 3. затем минимальная среди всех.
     */
    private static function fallbackMainPriceFromRanges(
        array $rangePrices,
        int $basketQty,
        array $accessibleIds = [],
        array $catalogGroups = []
    ): ?array {
        $allCandidates = [];

        foreach ($rangePrices as $gid => $rows) {
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $selected = self::pickRangeForBasketQty($rows, $basketQty);
            if ($selected === null || !isset($selected['price'])) {
                continue;
            }

            $gid = (int)$gid;
            $allCandidates[] = [
                'groupId' => $gid,
                'price'   => (float)$selected['price'],
                'canBuy'  => in_array($gid, $accessibleIds, true),
                'base'    => !empty($catalogGroups[$gid]['base']),
                'ord'     => 0,
            ];
        }

        if (empty($allCandidates)) {
            return null;
        }

        $buyableCandidates = [];
        foreach ($allCandidates as $candidate) {
            if (!empty($candidate['canBuy'])) {
                $buyableCandidates[] = $candidate;
            }
        }

        if (!empty($buyableCandidates)) {
            usort($buyableCandidates, [self::class, 'compareMainPriceCandidates']);
            return [
                'price'   => (float)$buyableCandidates[0]['price'],
                'groupId' => (int)$buyableCandidates[0]['groupId'],
            ];
        }

        $baseCandidates = [];
        foreach ($allCandidates as $candidate) {
            if (!empty($candidate['base'])) {
                $baseCandidates[] = $candidate;
            }
        }

        if (!empty($baseCandidates)) {
            usort($baseCandidates, [self::class, 'compareMainPriceCandidates']);
            return [
                'price'   => (float)$baseCandidates[0]['price'],
                'groupId' => (int)$baseCandidates[0]['groupId'],
            ];
        }

        usort($allCandidates, [self::class, 'compareMainPriceCandidates']);

        return [
            'price'   => (float)$allCandidates[0]['price'],
            'groupId' => (int)$allCandidates[0]['groupId'],
        ];
    }

    /**
     * Возвращает строку диапазона, соответствующую количеству basketQty.
     * Если точного диапазона нет — возвращает первую строку.
     */
    private static function pickRangeForBasketQty(array $rows, int $basketQty): ?array
    {
        foreach ($rows as $row) {
            if (!isset($row['price'])) {
                continue;
            }

            $from = $row['from'] ?? null;
            $to   = $row['to'] ?? null;

            $okFrom = ($from === null) || ($basketQty >= (int)$from);
            $okTo   = ($to === null) || ($basketQty <= (int)$to);

            if ($okFrom && $okTo) {
                return $row;
            }
        }

        $first = reset($rows);
        return is_array($first) ? $first : null;
    }

    /**
     * Схлопывает диапазонные цены к основной цене группы
     * (первая строка диапазонов после сортировки).
     *
     * @param array $rangePrices
     * @return array
     */
    private static function extractMainPricesFromRanges(array $rangePrices): array
    {
        $result = [];

        foreach ($rangePrices as $gid => $rows) {
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $first = reset($rows);
            if (is_array($first) && isset($first['price'])) {
                $result[(int)$gid] = (float)$first['price'];
            }
        }

        return $result;
    }

    /**
     * Форматирует цену в строку с символом рубля.
     */
    private static function formatPrice(float $price): string
    {
        $formatted = number_format(
            $price,
            abs(fmod($price, 1)) < 0.005 ? 0 : 2,
            '.',
            "\u{00A0}"
        );

        return $formatted . "\u{00A0}₽";
    }
}
