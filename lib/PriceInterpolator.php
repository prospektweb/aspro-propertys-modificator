<?php
/**
 * Серверная интерполяция цены для произвольных значений формата и тиража.
 *
 * Логика (зеркало клиентской части в script.js):
 *  - FORMAT: билинейная интерполяция по площади (ширина × высота)
 *  - VOLUME: линейная интерполяция по тиражу
 *  - Оба параметра произвольны: билинейная интерполяция по 4 угловым точкам
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Bitrix\Catalog\PriceTable;

class PriceInterpolator
{
    private int $productId;
    private int $offersIblockId;
    private int $priceTypeId;

    /** @var array Все ТП товара с распарсенными значениями FORMAT, VOLUME и ценой */
    private array $offerPoints = [];

    private bool $loaded = false;

    public function __construct(int $productId, ?int $offersIblockId = null, ?int $priceTypeId = null)
    {
        $this->productId      = $productId;
        $this->offersIblockId = $offersIblockId ?? Config::getOffersIblockId();
        $this->priceTypeId    = $priceTypeId    ?? Config::getPriceTypeId();
    }

    // ─── Публичный API ────────────────────────────────────────────────────────

    /**
     * Рассчитать интерполированную цену.
     *
     * @param int|null $width   Ширина в мм (null — берём стандартное ТП)
     * @param int|null $height  Высота в мм
     * @param int|null $volume  Тираж в шт.
     * @return float|null       Цена или null, если не удалось рассчитать
     */
    public function interpolate(?int $width, ?int $height, ?int $volume): ?float
    {
        $this->loadOfferPoints();

        if (empty($this->offerPoints)) {
            return null;
        }

        $customFormat = ($width !== null && $height !== null);
        $customVolume = ($volume !== null);

        if (!$customFormat && !$customVolume) {
            return null;
        }

        // Если оба параметра заданы — билинейная интерполяция
        if ($customFormat && $customVolume) {
            return $this->bilinearInterpolate($width, $height, $volume);
        }

        // Только формат изменён
        if ($customFormat) {
            return $this->interpolateByFormat($width, $height);
        }

        // Только тираж изменён
        return $this->interpolateByVolume($volume);
    }

    // ─── Загрузка данных ──────────────────────────────────────────────────────

    private function loadOfferPoints(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return;
        }

        $formatCode = Config::getFormatPropCode();
        $volumeCode = Config::getVolumePropCode();

        // Загружаем ID свойств для построения fallback-маппинга enumId → XML_ID.
        // Это нужно потому, что Bitrix может не возвращать PROPERTY_{CODE}_VALUE_XML_ID
        // для свойств типа «список» (L) в зависимости от версии и способа запроса.
        $formatPropId = null;
        $volumePropId = null;
        if ($formatCode) {
            $rsProp = \CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $this->offersIblockId, 'CODE' => $formatCode, 'ACTIVE' => 'Y']
            );
            if ($arProp = $rsProp->Fetch()) {
                $formatPropId = (int)$arProp['ID'];
            }
        }
        if ($volumeCode) {
            $rsProp = \CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $this->offersIblockId, 'CODE' => $volumeCode, 'ACTIVE' => 'Y']
            );
            if ($arProp = $rsProp->Fetch()) {
                $volumePropId = (int)$arProp['ID'];
            }
        }

        // Загружаем маппинги enumId → XML_ID для fallback
        $volumeEnumMap = [];
        if ($volumePropId) {
            $rsEnum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $volumePropId]);
            while ($arEnum = $rsEnum->Fetch()) {
                $enumId = (int)$arEnum['ID'];
                $xmlId  = trim((string)($arEnum['XML_ID'] ?? ''));
                if ($enumId > 0 && (is_numeric($xmlId) || $xmlId === 'X')) {
                    $volumeEnumMap[$enumId] = $xmlId === 'X' ? 'X' : (int)$xmlId;
                }
            }
        }

        $formatEnumMap = [];
        if ($formatPropId) {
            $rsEnum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $formatPropId]);
            while ($arEnum = $rsEnum->Fetch()) {
                $enumId = (int)$arEnum['ID'];
                $xmlId  = trim((string)($arEnum['XML_ID'] ?? ''));
                if ($enumId > 0 && $xmlId !== '') {
                    $formatEnumMap[$enumId] = $xmlId;
                }
            }
        }

        // Все ТП данного товара
        $rsOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $this->offersIblockId,
                'PROPERTY_CML2_LINK' => $this->productId,
                'ACTIVE' => 'Y',
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID', "PROPERTY_{$formatCode}", "PROPERTY_{$volumeCode}"]
        );

        $offerIds = [];
        $rawOffers = [];

        while ($arOffer = $rsOffers->Fetch()) {
            $offerId = (int)$arOffer['ID'];

            // Разбираем XML_ID значений свойств; fallback — через enumMap
            $formatXmlId = $arOffer["PROPERTY_{$formatCode}_VALUE_XML_ID"] ?? null;
            if (empty($formatXmlId) && !empty($arOffer["PROPERTY_{$formatCode}_VALUE"])) {
                $enumId = (int)$arOffer["PROPERTY_{$formatCode}_VALUE"];
                $formatXmlId = $formatEnumMap[$enumId] ?? null;
            }

            $volumeXmlId = $arOffer["PROPERTY_{$volumeCode}_VALUE_XML_ID"] ?? null;
            if (empty($volumeXmlId) && !empty($arOffer["PROPERTY_{$volumeCode}_VALUE"])) {
                $enumId = (int)$arOffer["PROPERTY_{$volumeCode}_VALUE"];
                $volumeXmlId = $volumeEnumMap[$enumId] ?? null;
                // volumeEnumMap хранит int|'X', приводим к строке для парсера
                if ($volumeXmlId !== null) {
                    $volumeXmlId = (string)$volumeXmlId;
                }
            }

            $formatParsed = $formatXmlId ? PropertyValidator::parseFormatXmlId($formatXmlId) : null;
            $volumeParsed = $volumeXmlId ? PropertyValidator::parseVolumeXmlId($volumeXmlId) : null;

            if (!$formatParsed && !$volumeParsed) {
                continue;
            }

            $rawOffers[$offerId] = [
                'id'     => $offerId,
                'width'  => $formatParsed['width']  ?? null,
                'height' => $formatParsed['height'] ?? null,
                'volume' => $volumeParsed,
                'price'  => null,
            ];

            $offerIds[] = $offerId;
        }

        if (empty($offerIds)) {
            return;
        }

        // Загружаем цены одним запросом
        $rsPrices = PriceTable::getList([
            'filter' => [
                '=PRODUCT_ID'       => $offerIds,
                '=CATALOG_GROUP_ID' => $this->priceTypeId,
            ],
            'select' => ['PRODUCT_ID', 'PRICE'],
        ]);

        while ($arPrice = $rsPrices->fetch()) {
            $id = (int)$arPrice['PRODUCT_ID'];
            if (isset($rawOffers[$id])) {
                $rawOffers[$id]['price'] = (float)$arPrice['PRICE'];
            }
        }

        // Оставляем только ТП с ценами
        $this->offerPoints = array_filter($rawOffers, fn($o) => $o['price'] !== null);
    }

    // ─── Методы интерполяции ──────────────────────────────────────────────────

    /**
     * Линейная интерполяция по тиражу (формат — стандартный).
     */
    private function interpolateByVolume(int $volume): ?float
    {
        // Берём все ТП с числовым объёмом, сортируем
        $points = array_filter($this->offerPoints, fn($o) => $o['volume'] !== null);
        $points = array_values($points);
        usort($points, fn($a, $b) => $a['volume'] <=> $b['volume']);

        if (empty($points)) {
            return null;
        }

        return $this->linearInterp($points, 'volume', $volume);
    }

    /**
     * Линейная интерполяция по площади (тираж — стандартный).
     */
    private function interpolateByFormat(int $width, int $height): ?float
    {
        $area = $width * $height;

        $points = array_filter($this->offerPoints, fn($o) => $o['width'] !== null && $o['height'] !== null);
        $points = array_map(function ($o) {
            $o['area'] = $o['width'] * $o['height'];
            return $o;
        }, $points);
        $points = array_values($points);
        usort($points, fn($a, $b) => $a['area'] <=> $b['area']);

        if (empty($points)) {
            return null;
        }

        return $this->linearInterp($points, 'area', $area);
    }

    /**
     * Билинейная интерполяция (оба параметра произвольны).
     *
     * Находим 4 угловых ТП (нижний/верхний по площади × нижний/верхний по тиражу)
     * и интерполируем.
     */
    private function bilinearInterpolate(int $width, int $height, int $volume): ?float
    {
        $area = $width * $height;

        $points = array_filter(
            $this->offerPoints,
            fn($o) => $o['width'] !== null && $o['height'] !== null && $o['volume'] !== null
        );

        if (empty($points)) {
            // Фолбэк: попробовать интерполировать по площади
            return $this->interpolateByFormat($width, $height);
        }

        foreach ($points as &$o) {
            $o['area'] = $o['width'] * $o['height'];
        }
        unset($o);

        $areas   = array_unique(array_column($points, 'area'));
        $volumes = array_unique(array_column($points, 'volume'));
        sort($areas);
        sort($volumes);

        // Находим соседей по каждому измерению
        [$areaLow, $areaHigh]     = $this->findNeighbors($areas, $area);
        [$volumeLow, $volumeHigh] = $this->findNeighbors($volumes, $volume);

        // Если одно или оба значения совпадают (граничный случай) — линейная интерполяция
        if ($areaLow === $areaHigh) {
            $p1 = $this->findClosestPoint($points, $areaLow, $volumeLow);
            $p2 = $this->findClosestPoint($points, $areaLow, $volumeHigh);
            return $this->lerp($p1, $p2, $volumeLow, $volumeHigh, $volume);
        }

        if ($volumeLow === $volumeHigh) {
            $p1 = $this->findClosestPoint($points, $areaLow, $volumeLow);
            $p2 = $this->findClosestPoint($points, $areaHigh, $volumeLow);
            return $this->lerp($p1, $p2, $areaLow, $areaHigh, $area);
        }

        // 4 угловые точки
        $q11 = $this->findClosestPoint($points, $areaLow,  $volumeLow);
        $q12 = $this->findClosestPoint($points, $areaLow,  $volumeHigh);
        $q21 = $this->findClosestPoint($points, $areaHigh, $volumeLow);
        $q22 = $this->findClosestPoint($points, $areaHigh, $volumeHigh);

        if ($q11 === null || $q12 === null || $q21 === null || $q22 === null) {
            return $this->interpolateByFormat($width, $height);
        }

        // Интерполируем сначала по площади, потом по тиражу
        $r1 = $this->lerp($q11, $q21, $areaLow, $areaHigh, $area);
        $r2 = $this->lerp($q12, $q22, $areaLow, $areaHigh, $area);

        if ($r1 === null || $r2 === null) {
            return null;
        }

        return $this->lerp($r1, $r2, $volumeLow, $volumeHigh, $volume);
    }

    // ─── Утилиты ──────────────────────────────────────────────────────────────

    /**
     * Найти нижнего и верхнего соседа значения в отсортированном массиве.
     *
     * @return array [low, high]
     */
    private function findNeighbors(array $sorted, int|float $value): array
    {
        $low  = $sorted[0];
        $high = $sorted[count($sorted) - 1];

        // Итерируем по отсортированному массиву:
        // low  = наибольшее значение ≤ value
        // high = наименьшее значение ≥ value (первое совпадение → break)
        foreach ($sorted as $v) {
            if ($v <= $value) {
                $low = $v;
            }
            if ($v >= $value) {
                $high = $v;
                break;
            }
        }

        return [$low, $high];
    }

    /**
     * Найти ТП с заданными значениями area и volume (ближайшие).
     */
    private function findClosestPoint(array $points, int|float $area, int $volume): ?float
    {
        $best     = null;
        $bestDist = PHP_INT_MAX;

        foreach ($points as $p) {
            $dist = abs($p['area'] - $area) + abs($p['volume'] - $volume);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $p['price'];
            }
        }

        return $best;
    }

    /**
     * Линейная интерполяция массива точек по заданному ключу.
     */
    private function linearInterp(array $sorted, string $key, int|float $value): float
    {
        // Если значение вне диапазона — возвращаем граничную цену
        if ($value <= $sorted[0][$key]) {
            return $sorted[0]['price'];
        }
        if ($value >= $sorted[count($sorted) - 1][$key]) {
            return $sorted[count($sorted) - 1]['price'];
        }

        for ($i = 0; $i < count($sorted) - 1; $i++) {
            $lo = $sorted[$i];
            $hi = $sorted[$i + 1];

            if ($value >= $lo[$key] && $value <= $hi[$key]) {
                $t = ($value - $lo[$key]) / ($hi[$key] - $lo[$key]);
                return $lo['price'] + $t * ($hi['price'] - $lo['price']);
            }
        }

        return $sorted[count($sorted) - 1]['price'];
    }

    /**
     * Линейная интерполяция двух скалярных цен.
     */
    private function lerp(float $p1, float $p2, int|float $lo, int|float $hi, int|float $val): float
    {
        if ($lo === $hi) {
            return $p1;
        }

        $t = ($val - $lo) / ($hi - $lo);
        return $p1 + $t * ($p2 - $p1);
    }
}
