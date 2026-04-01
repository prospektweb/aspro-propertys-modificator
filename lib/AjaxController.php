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
     * @return array JSON-ответ
     */
    public static function calcPrice(): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return ['success' => false, 'error' => 'Required modules not loaded'];
        }

        // ── Входные параметры ─────────────────────────────────────────────────

        $productId  = (int)($_POST['productId']  ?? $_GET['productId']  ?? 0);
        $volume     = isset($_POST['volume'])  && $_POST['volume'] !== ''  ? (int)$_POST['volume']  : null;
        $width      = isset($_POST['width'])   && $_POST['width']  !== ''  ? (int)$_POST['width']   : null;
        $height     = isset($_POST['height'])  && $_POST['height'] !== ''  ? (int)$_POST['height']  : null;
        $basketQty  = isset($_POST['basket_qty']) && (int)$_POST['basket_qty'] > 0 ? (int)$_POST['basket_qty'] : 1;
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

        // Применяем округление
        foreach ($rawPrices as $gid => &$price) {
            if (!empty($roundingRules[$gid])) {
                $price = self::applyRounding($price, $roundingRules[$gid]);
            }
        }
        unset($price);
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

        // ── Определяем доступность типов цен для текущего пользователя ────────

        $accessibleGroupIds = self::getAccessiblePriceGroups();

        // ── Формируем ответ ────────────────────────────────────────────────────

        $pricesResult = [];
        foreach ($rawPrices as $gid => $price) {
            $group = $catalogGroups[$gid] ?? null;
            $pricesResult[$gid] = [
                'raw'       => round($price, 2),
                'formatted' => self::formatPrice($price),
                'groupName' => $group ? (string)$group['name'] : '',
                'canBuy'    => in_array((int)$gid, $accessibleGroupIds, true),
            ];
        }

        $mainPrice = !empty($rangePrices)
            ? self::determineMainPriceFromRanges($rangePrices, $accessibleGroupIds, $basketQty, $catalogGroups, $visibleGroups, $activeGroupId)
            : self::determineMainPrice($rawPrices, $catalogGroups, $accessibleGroupIds, $activeGroupId);

        if ($mainPrice === null && !empty($rangePrices)) {
            $mainPrice = self::fallbackMainPriceFromRanges($rangePrices, $basketQty);
        }

        $result = [
            'success'   => true,
            'prices'    => $pricesResult,
            'ranges'    => $rangePrices,
            'mainPrice' => $mainPrice !== null ? [
                'raw'       => round($mainPrice['price'], 2),
                'formatted' => self::formatPrice($mainPrice['price']),
                'groupId'   => $mainPrice['groupId'],
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
     * Загружает информацию о типах цен (catalog groups).
     *
     * @return array [groupId => {id, name, base}]
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
                    'base' => ($ar['BASE'] ?? 'N') === 'Y',
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
     * @return array [groupId => [{price, type, precision}, ...]]
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
     * Выбирается правило с наибольшим порогом price, не превышающим текущую цену.
     *
     * @param float $price
     * @param array $rules [{price, type, precision}]
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
            case 1:  // ROUND_DOWN
                return floor($price / $precision) * $precision;
            case 2:  // ROUND_MATH
                return round($price / $precision) * $precision;
            case 3:  // ROUND_UP
                return ceil($price / $precision) * $precision;
            default:
                return $price;
        }
    }

    /**
     * Возвращает список ID типов цен, доступных текущему пользователю для покупки.
     *
     * Использует CCatalogGroup::GetGroupsPerms() для определения прав
     * групп пользователя. Если определить не удаётся — возвращает пустой массив
     * (вызывающий код использует базовую группу как запасной вариант).
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
                    foreach ($perms as $gid => $perm) {
                        if (isset($perm['buy']) && $perm['buy'] === 'Y') {
                            $canBuyGroupIds[] = (int)$gid;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // продолжаем без данных о правах
            }
        }

        return $canBuyGroupIds;
    }

    /**
     * Определяет «главную» цену для покупки.
     *
     * Приоритет выбора:
     *  1. Первая группа, доступная пользователю для покупки (по возрастанию ID)
     *  2. Базовая группа (BASE = Y)
     *  3. Первая доступная группа в rawPrices (числовой порядок ключей)
     *
     * Это поведение соответствует логике Bitrix/Aspro для определения
     * «активной» цены на карточке товара.
     *
     * @param array $rawPrices       [groupId => float]
     * @param array $catalogGroups   [groupId => {id, name, base}]
     * @param array $accessibleIds   [groupId] — доступные для покупки
     * @return array|null            ['price' => float, 'groupId' => int]
     */
    private static function determineMainPrice(
        array $rawPrices,
        array $catalogGroups,
        array $accessibleIds,
        ?int $preferredGroupId = null
    ): ?array {
        $sortedGids = array_keys($rawPrices);
        sort($sortedGids);

        // 0. Предпочтительная активная группа (из фронта), если доступна.
        if (
            $preferredGroupId !== null
            && isset($rawPrices[$preferredGroupId])
            && (empty($accessibleIds) || in_array((int)$preferredGroupId, $accessibleIds, true))
        ) {
            return ['price' => $rawPrices[$preferredGroupId], 'groupId' => (int)$preferredGroupId];
        }

        // 1. Доступные для покупки
        foreach ($sortedGids as $gid) {
            if (in_array((int)$gid, $accessibleIds, true) && isset($rawPrices[$gid])) {
                return ['price' => $rawPrices[$gid], 'groupId' => (int)$gid];
            }
        }

        // 2. Базовая группа
        foreach ($sortedGids as $gid) {
            $group = $catalogGroups[$gid] ?? null;
            if ($group && $group['base'] && isset($rawPrices[$gid])) {
                return ['price' => $rawPrices[$gid], 'groupId' => (int)$gid];
            }
        }

        // 3. Первая доступная
        if (!empty($sortedGids) && isset($rawPrices[$sortedGids[0]])) {
            return ['price' => $rawPrices[$sortedGids[0]], 'groupId' => (int)$sortedGids[0]];
        }

        return null;
    }

    /**
     * Определяет главную цену с учётом диапазонов количества и прав покупки.
     * Выбирает минимальную цену среди доступных для покупки групп в диапазоне basketQty.
     *
     * @param array $rangePrices     [groupId => [{from,to,price}, ...]]
     * @param array $accessibleIds   [groupId]
     * @param int   $basketQty       Количество тиражей в корзине (обычно 1)
     * @param array $catalogGroups   [groupId => {id, name, base}]
     * @return array|null            ['price' => float, 'groupId' => int]
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

        // 0) Предпочтительная активная группа (из фронта), если она доступна.
        if (
            $preferredGroupId !== null
            && !empty($rowsByGroup[$preferredGroupId])
            && (empty($accessibleIds) || in_array($preferredGroupId, $accessibleIds, true))
        ) {
            $selectedPref = self::pickRangeForBasketQty($rowsByGroup[$preferredGroupId], $basketQty);
            if ($selectedPref !== null) {
                return ['price' => (float)$selectedPref['price'], 'groupId' => (int)$preferredGroupId];
            }
        }

        $allowedOrder = [];
        if (!empty($visibleGroups)) {
            foreach ($visibleGroups as $gid) {
                $gid = (int)$gid;
                if (!empty($rowsByGroup[$gid])) {
                    $allowedOrder[] = $gid;
                }
            }
        }
        if (empty($allowedOrder)) {
            $allowedOrder = array_keys($rowsByGroup);
            sort($allowedOrder);
        }

        $orderLookup = [];
        foreach ($allowedOrder as $idx => $gid) {
            $orderLookup[$gid] = $idx;
        }

        $candidates = [];
        foreach ($allowedOrder as $gid) {
            $selected = self::pickRangeForBasketQty($rowsByGroup[$gid], $basketQty);
            if ($selected === null) {
                continue;
            }
            $candidates[] = [
                'groupId' => $gid,
                'price'   => (float)$selected['price'],
                'canBuy'  => in_array($gid, $accessibleIds, true),
                'base'    => !empty($catalogGroups[$gid]['base']),
                'ord'     => $orderLookup[$gid] ?? PHP_INT_MAX,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        $buyable = [];
        foreach ($candidates as $candidate) {
            if (!empty($candidate['canBuy'])) {
                $buyable[] = $candidate;
            }
        }
        $pool = !empty($buyable) ? $buyable : $candidates;

        usort($pool, [self::class, 'compareMainPriceCandidates']);

        return ['price' => $pool[0]['price'], 'groupId' => (int)$pool[0]['groupId']];
    }

    private static function compareMainPriceCandidates(array $a, array $b): int
    {
        if ($a['price'] === $b['price']) {
            if ($a['ord'] !== $b['ord']) {
                return $a['ord'] <=> $b['ord'];
            }
            if ($a['base'] !== $b['base']) {
                return $a['base'] ? -1 : 1;
            }
            return $a['groupId'] <=> $b['groupId'];
        }
        return $a['price'] <=> $b['price'];
    }

    private static function fallbackMainPriceFromRanges(array $rangePrices, int $basketQty): ?array
    {
        $best = null;
        foreach ($rangePrices as $gid => $rows) {
            if (!is_array($rows) || empty($rows)) {
                continue;
            }
            $selected = self::pickRangeForBasketQty($rows, $basketQty);
            if ($selected === null || !isset($selected['price'])) {
                continue;
            }
            $price = (float)$selected['price'];
            if ($best === null || $price < $best['price']) {
                $best = ['price' => $price, 'groupId' => (int)$gid];
            }
        }
        return $best;
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
     * Схлопывает диапазонные цены к «основной» цене группы
     * (первая строка диапазонов после сортировки).
     *
     * @param array $rangePrices [groupId => [{from,to,price}, ...]]
     * @return array [groupId => float]
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
     * Форматирует цену в строку с символом рубля (например: «12 346 ₽»).
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
