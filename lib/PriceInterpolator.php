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
     * @param int|null   $width        Ширина в мм (null — берём стандартное ТП)
     * @param int|null   $height       Высота в мм
     * @param int|null   $volume       Тираж в шт.
     * @param array|null $filterProps  Дополнительная фильтрация: [propId => enumId]
     * @return float|null              Цена или null, если не удалось рассчитать
     */
    public function interpolate(?int $width, ?int $height, ?int $volume, ?array $filterProps = null): ?float
    {
        $this->loadOfferPoints($filterProps);

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

    private function loadOfferPoints(?array $filterProps = null): void
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
        // Это нужно потому, что Bitrix для свойств типа «список» (L) в GetList
        // возвращает PROPERTY_{CODE}_ENUM_ID, а не PROPERTY_{CODE}_VALUE_XML_ID.
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

        // Загружаем "прочие" свойства типа «список» для фильтрации ТП
        $otherProps = []; // [propId => code]
        if ($filterProps !== null && !empty($filterProps)) {
            $rsPropList = \CIBlockProperty::GetList(['SORT' => 'ASC'], [
                'IBLOCK_ID'     => $this->offersIblockId,
                'PROPERTY_TYPE' => 'L',
                'ACTIVE'        => 'Y',
            ]);
            while ($arProp = $rsPropList->Fetch()) {
                $code   = (string)$arProp['CODE'];
                $propId = (int)$arProp['ID'];
                if (
                    $propId > 0
                    && $code !== $volumeCode
                    && $code !== $formatCode
                    && $code !== 'CML2_LINK'
                    && isset($filterProps[$propId])
                ) {
                    $otherProps[$propId] = $code;
                }
            }
        }

        // Все ТП данного товара
        $selectFields = [
            'ID',
            'IBLOCK_ID',
            "PROPERTY_{$formatCode}",
            "PROPERTY_{$volumeCode}",
        ];
        foreach ($otherProps as $propId => $code) {
            $selectFields[] = "PROPERTY_{$code}";
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
            $selectFields
        );

        $offerIds = [];
        $rawOffers = [];

        while ($arOffer = $rsOffers->Fetch()) {
            $offerId = (int)$arOffer['ID'];

            // Разбираем XML_ID значений свойств; fallback — через enumMap по ENUM_ID
            $formatXmlId = $arOffer["PROPERTY_{$formatCode}_VALUE_XML_ID"] ?? null;
            if (empty($formatXmlId)) {
                $enumId = (int)($arOffer["PROPERTY_{$formatCode}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $formatXmlId = $formatEnumMap[$enumId] ?? null;
                }
            }

            $volumeXmlId = $arOffer["PROPERTY_{$volumeCode}_VALUE_XML_ID"] ?? null;
            if (empty($volumeXmlId)) {
                $enumId = (int)($arOffer["PROPERTY_{$volumeCode}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $volumeXmlId = $volumeEnumMap[$enumId] ?? null;
                    // volumeEnumMap хранит int|'X', приводим к строке для парсера
                    if ($volumeXmlId !== null) {
                        $volumeXmlId = (string)$volumeXmlId;
                    }
                }
            }

            $formatParsed = $formatXmlId ? PropertyValidator::parseFormatXmlId($formatXmlId) : null;
            $volumeParsed = $volumeXmlId ? PropertyValidator::parseVolumeXmlId($volumeXmlId) : null;

            if (!$formatParsed && !$volumeParsed) {
                continue;
            }

            // Собираем "прочие" свойства типа «список» для фильтрации
            $props = [];
            foreach ($otherProps as $propId => $code) {
                $enumId = (int)($arOffer["PROPERTY_{$code}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $props[$propId] = $enumId;
                }
            }

            // Фильтруем по переданным свойствам
            if ($filterProps !== null && !empty($filterProps)) {
                $skip = false;
                foreach ($filterProps as $propId => $requiredEnumId) {
                    if (!isset($props[$propId]) || $props[$propId] !== $requiredEnumId) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }

            $rawOffers[$offerId] = [
                'id'     => $offerId,
                'width'  => $formatParsed['width']  ?? null,
                'height' => $formatParsed['height'] ?? null,
                'volume' => $volumeParsed,
                'price'  => null,
                'props'  => $props,
            ];

            $offerIds[] = $offerId;
        }

        if (empty($offerIds)) {
            return;
        }

        // Загружаем цену для настроенного типа цены
        // Берём все записи, сортируем по QUANTITY_FROM ASC, используем первую на ТП
        // (то есть диапазон с минимальным QUANTITY_FROM / null = 1 единица заказа)
        $rsPrices = PriceTable::getList([
            'filter' => [
                '=PRODUCT_ID'       => $offerIds,
                '=CATALOG_GROUP_ID' => $this->priceTypeId,
            ],
            'select' => ['PRODUCT_ID', 'PRICE', 'QUANTITY_FROM', 'QUANTITY_TO'],
            'order'  => ['PRODUCT_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC'],
        ]);

        while ($arPrice = $rsPrices->fetch()) {
            $id = (int)$arPrice['PRODUCT_ID'];
            // Берём первую запись (минимальный QUANTITY_FROM) для каждого ТП
            if (isset($rawOffers[$id]) && $rawOffers[$id]['price'] === null) {
                $rawOffers[$id]['price'] = (float)$arPrice['PRICE'];
            }
        }

        // Оставляем только ТП с ценами.
        // volume !== null — исключаем X-ТП (произвольный тираж).
        // price > 0 — исключаем плейсхолдерные нулевые/символические цены.
        $this->offerPoints = array_filter(
            $rawOffers,
            fn($o) => $o['price'] !== null && $o['price'] > 0 && $o['volume'] !== null
        );
    }

    // ─── Интерполяция по всем типам цен ──────────────────────────────────────

    /**
     * Рассчитать интерполированные цены для всех групп типов цен.
     *
     * В отличие от interpolate(), который работает с одним $priceTypeId,
     * этот метод загружает цены всех групп одним запросом и возвращает
     * результаты для каждой из них.
     *
     * @param int|null   $width        Ширина в мм
     * @param int|null   $height       Высота в мм
     * @param int|null   $volume       Тираж
     * @param array|null $filterProps  Дополнительная фильтрация: [propId => enumId]
     * @return array                   [groupId => float]
     */
    public function interpolateAllGroups(?int $width, ?int $height, ?int $volume, ?array $filterProps = null): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return [];
        }

        $rawMeta = $this->loadOfferMetadata($filterProps);
        if (empty($rawMeta)) {
            return [];
        }

        $customFormat = ($width !== null && $height !== null);
        $customVolume = ($volume !== null);

        if (!$customFormat && !$customVolume) {
            return [];
        }

        $offerIds = array_keys($rawMeta);

        // Загружаем цены всех групп за один запрос
        // QUANTITY_FROM ASC → берём первую запись на каждую пару (PRODUCT_ID, CATALOG_GROUP_ID)
        $rsPrices = PriceTable::getList([
            'filter' => ['=PRODUCT_ID' => $offerIds],
            'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'QUANTITY_FROM'],
            'order'  => ['PRODUCT_ID' => 'ASC', 'CATALOG_GROUP_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC'],
        ]);

        // [groupId => [offerId => price]]
        $pricesByGroup = [];
        while ($ar = $rsPrices->fetch()) {
            $oid = (int)$ar['PRODUCT_ID'];
            $gid = (int)$ar['CATALOG_GROUP_ID'];
            // Берём только первую запись (min QUANTITY_FROM)
            if (!isset($pricesByGroup[$gid][$oid])) {
                $pricesByGroup[$gid][$oid] = (float)$ar['PRICE'];
            }
        }

        if (empty($pricesByGroup)) {
            return [];
        }

        $result = [];
        foreach ($pricesByGroup as $gid => $groupPrices) {
            // Собираем offerPoints для данной группы
            $points = [];
            foreach ($rawMeta as $oid => $meta) {
                $price = $groupPrices[$oid] ?? null;
                // Пропускаем X-ТП (volume === null) и нулевые/символические цены
                if ($price === null || $price <= 0 || $meta['volume'] === null) {
                    continue;
                }
                $points[] = array_merge($meta, ['price' => $price]);
            }

            if (empty($points)) {
                continue;
            }

            $price = $this->interpolatePoints($points, $width, $height, $volume);
            if ($price !== null) {
                $result[$gid] = $price;
            }
        }

        return $result;
    }

    /**
     * Рассчитать интерполированные цены по всем группам и диапазонам количества.
     *
     * Результат:
     * [
     *   groupId => [
     *     ['from' => ?int, 'to' => ?int, 'price' => float],
     *     ...
     *   ],
     *   ...
     * ]
     */
    public function interpolateAllGroupsWithRanges(?int $width, ?int $height, ?int $volume, ?array $filterProps = null): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return [];
        }

        $rawMeta = $this->loadOfferMetadata($filterProps);
        if (empty($rawMeta)) {
            return [];
        }

        $customFormat = ($width !== null && $height !== null);
        $customVolume = ($volume !== null);
        if (!$customFormat && !$customVolume) {
            return [];
        }

        $offerIds = array_keys($rawMeta);
        $rsPrices = PriceTable::getList([
            'filter' => ['=PRODUCT_ID' => $offerIds],
            'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'QUANTITY_FROM', 'QUANTITY_TO'],
            'order'  => [
                'CATALOG_GROUP_ID' => 'ASC',
                'QUANTITY_FROM'    => 'ASC',
                'QUANTITY_TO'      => 'ASC',
                'PRODUCT_ID'       => 'ASC',
            ],
        ]);

        // [groupId => [rangeKey => ['from'=>?int,'to'=>?int,'points'=>[...]]]]
        $rangesByGroup = [];
        while ($ar = $rsPrices->fetch()) {
            $oid = (int)$ar['PRODUCT_ID'];
            $gid = (int)$ar['CATALOG_GROUP_ID'];

            if (!isset($rawMeta[$oid])) {
                continue;
            }

            $meta = $rawMeta[$oid];
            if (($meta['volume'] ?? null) === null) {
                // исключаем X-ТП
                continue;
            }

            $price = (float)$ar['PRICE'];
            if ($price <= 0) {
                continue;
            }

            $from = $ar['QUANTITY_FROM'] !== null ? (int)$ar['QUANTITY_FROM'] : null;
            $to   = $ar['QUANTITY_TO']   !== null ? (int)$ar['QUANTITY_TO']   : null;
            $key  = ($from === null ? '' : (string)$from) . '-' . ($to === null ? '' : (string)$to);

            if (!isset($rangesByGroup[$gid][$key])) {
                $rangesByGroup[$gid][$key] = [
                    'from'   => $from,
                    'to'     => $to,
                    'points' => [],
                ];
            }

            $rangesByGroup[$gid][$key]['points'][] = array_merge($meta, ['price' => $price]);
        }

        if (empty($rangesByGroup)) {
            return [];
        }

        $result = [];
        foreach ($rangesByGroup as $gid => $rangeMap) {
            $rows = [];
            foreach ($rangeMap as $range) {
                $points = $range['points'];
                if (empty($points)) {
                    continue;
                }
                $price = $this->interpolatePoints($points, $width, $height, $volume);
                if ($price === null) {
                    continue;
                }
                $rows[] = [
                    'from'  => $range['from'],
                    'to'    => $range['to'],
                    'price' => $price,
                ];
            }

            usort($rows, static function (array $a, array $b): int {
                $af = $a['from'] ?? PHP_INT_MIN;
                $bf = $b['from'] ?? PHP_INT_MIN;
                if ($af === $bf) {
                    $at = $a['to'] ?? PHP_INT_MAX;
                    $bt = $b['to'] ?? PHP_INT_MAX;
                    return $at <=> $bt;
                }
                return $af <=> $bf;
            });

            if (!empty($rows)) {
                $result[$gid] = $rows;
            }
        }

        return $result;
    }

    /**
     * Загружает метаданные ТП (без цен): id, width, height, volume, props.
     * Результат не кэшируется — вызывается из interpolateAllGroups().
     *
     * @param array|null $filterProps [propId => enumId]
     * @return array [offerId => [id, width, height, volume, props]]
     */
    private function loadOfferMetadata(?array $filterProps): array
    {
        $formatCode = Config::getFormatPropCode();
        $volumeCode = Config::getVolumePropCode();

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

        $otherProps = [];
        if ($filterProps !== null && !empty($filterProps)) {
            $rsPropList = \CIBlockProperty::GetList(['SORT' => 'ASC'], [
                'IBLOCK_ID'     => $this->offersIblockId,
                'PROPERTY_TYPE' => 'L',
                'ACTIVE'        => 'Y',
            ]);
            while ($arProp = $rsPropList->Fetch()) {
                $code   = (string)$arProp['CODE'];
                $propId = (int)$arProp['ID'];
                if (
                    $propId > 0
                    && $code !== $volumeCode
                    && $code !== $formatCode
                    && $code !== 'CML2_LINK'
                    && isset($filterProps[$propId])
                ) {
                    $otherProps[$propId] = $code;
                }
            }
        }

        $selectFields = [
            'ID',
            'IBLOCK_ID',
            "PROPERTY_{$formatCode}",
            "PROPERTY_{$volumeCode}",
        ];
        foreach ($otherProps as $propId => $code) {
            $selectFields[] = "PROPERTY_{$code}";
        }

        $rsOffers = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID'          => $this->offersIblockId,
                'PROPERTY_CML2_LINK' => $this->productId,
                'ACTIVE'             => 'Y',
            ],
            false,
            false,
            $selectFields
        );

        $meta = [];
        while ($arOffer = $rsOffers->Fetch()) {
            $offerId = (int)$arOffer['ID'];

            $formatXmlId = $arOffer["PROPERTY_{$formatCode}_VALUE_XML_ID"] ?? null;
            if (empty($formatXmlId)) {
                $enumId = (int)($arOffer["PROPERTY_{$formatCode}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $formatXmlId = $formatEnumMap[$enumId] ?? null;
                }
            }

            $volumeXmlId = $arOffer["PROPERTY_{$volumeCode}_VALUE_XML_ID"] ?? null;
            if (empty($volumeXmlId)) {
                $enumId = (int)($arOffer["PROPERTY_{$volumeCode}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $volumeXmlId = $volumeEnumMap[$enumId] ?? null;
                    if ($volumeXmlId !== null) {
                        $volumeXmlId = (string)$volumeXmlId;
                    }
                }
            }

            $formatParsed = $formatXmlId ? PropertyValidator::parseFormatXmlId($formatXmlId) : null;
            $volumeParsed = $volumeXmlId ? PropertyValidator::parseVolumeXmlId($volumeXmlId) : null;

            if (!$formatParsed && !$volumeParsed) {
                continue;
            }

            $props = [];
            foreach ($otherProps as $propId => $code) {
                $enumId = (int)($arOffer["PROPERTY_{$code}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $props[$propId] = $enumId;
                }
            }

            if ($filterProps !== null && !empty($filterProps)) {
                $skip = false;
                foreach ($filterProps as $propId => $requiredEnumId) {
                    if (!isset($props[$propId]) || $props[$propId] !== $requiredEnumId) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }

            $meta[$offerId] = [
                'id'     => $offerId,
                'width'  => $formatParsed['width']  ?? null,
                'height' => $formatParsed['height'] ?? null,
                'volume' => $volumeParsed,
                'props'  => $props,
            ];
        }

        return $meta;
    }

    /**
     * Запускает нужный вид интерполяции для переданного набора точек.
     *
     * @param array    $offerPoints [{id, width, height, volume, price, props}, ...]
     * @param int|null $width
     * @param int|null $height
     * @param int|null $volume
     * @return float|null
     */
    private function interpolatePoints(array $offerPoints, ?int $width, ?int $height, ?int $volume): ?float
    {
        $customFormat = ($width !== null && $height !== null);
        $customVolume = ($volume !== null);

        if ($customFormat && $customVolume) {
            return $this->bilinearInterpolatePoints($offerPoints, $width, $height, $volume);
        }
        if ($customFormat) {
            return $this->interpolateByFormatPoints($offerPoints, $width, $height);
        }
        return $this->interpolateByVolumePoints($offerPoints, $volume);
    }

    private function interpolateByVolumePoints(array $offerPoints, int $volume): ?float
    {
        $points = array_filter($offerPoints, fn($o) => $o['volume'] !== null);
        $points = array_values($points);
        usort($points, fn($a, $b) => $a['volume'] <=> $b['volume']);

        if (empty($points)) {
            return null;
        }

        return $this->linearInterp($points, 'volume', $volume);
    }

    private function interpolateByFormatPoints(array $offerPoints, int $width, int $height): ?float
    {
        $area = $width * $height;

        $points = array_filter($offerPoints, fn($o) => $o['width'] !== null && $o['height'] !== null);
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

    private function bilinearInterpolatePoints(array $offerPoints, int $width, int $height, int $volume): ?float
    {
        $area = $width * $height;

        $points = array_filter(
            $offerPoints,
            fn($o) => $o['width'] !== null && $o['height'] !== null && $o['volume'] !== null
        );

        if (empty($points)) {
            return $this->interpolateByFormatPoints($offerPoints, $width, $height);
        }

        foreach ($points as &$o) {
            $o['area'] = $o['width'] * $o['height'];
        }
        unset($o);

        $areas   = array_unique(array_column($points, 'area'));
        $volumes = array_unique(array_column($points, 'volume'));
        sort($areas);
        sort($volumes);

        [$areaLow, $areaHigh]     = $this->findNeighbors($areas, $area);
        [$volumeLow, $volumeHigh] = $this->findNeighbors($volumes, $volume);

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

        $q11 = $this->findClosestPoint($points, $areaLow,  $volumeLow);
        $q12 = $this->findClosestPoint($points, $areaLow,  $volumeHigh);
        $q21 = $this->findClosestPoint($points, $areaHigh, $volumeLow);
        $q22 = $this->findClosestPoint($points, $areaHigh, $volumeHigh);

        if ($q11 === null || $q12 === null || $q21 === null || $q22 === null) {
            return $this->interpolateByFormatPoints($offerPoints, $width, $height);
        }

        $r1 = $this->lerp($q11, $q21, $areaLow, $areaHigh, $area);
        $r2 = $this->lerp($q12, $q22, $areaLow, $areaHigh, $area);

        if ($r1 === null || $r2 === null) {
            return null;
        }

        return $this->lerp($r1, $r2, $volumeLow, $volumeHigh, $volume);
    }

    // ─── Методы интерполяции (существующие, работают с $this->offerPoints) ────

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
