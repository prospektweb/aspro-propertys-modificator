<?php

namespace Prospektweb\PropModificator;

use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Domain\Offer\EnumValueResolver;

/**
 * Reads offer metadata and price rows from Bitrix catalog/iblock tables.
 *
 * Input: productId and optional property filters.
 * Output: normalized offers metadata and grouped prices/ranges.
 */
class OfferRepository
{
    public function __construct(private ?EnumValueResolver $enumValueResolver = null)
    {
        $this->enumValueResolver = $this->enumValueResolver ?? new EnumValueResolver();
    }

    public function loadOfferMetadata(int $productId, ?array $filterProps): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return [];
        }

        $offersIblockId = Config::getOffersIblockId();
        $formatCode = Config::getFormatPropCode();
        $volumeCode = Config::getVolumePropCode();

        $formatPropId = $this->resolvePropId($offersIblockId, $formatCode);
        $volumePropId = $this->resolvePropId($offersIblockId, $volumeCode);

        $volumeEnumMap = $this->loadVolumeEnumMap($volumePropId);
        $formatEnumMap = $this->enumValueResolver->loadEnumXmlMap($formatPropId);

        $otherProps = [];
        if ($filterProps !== null && !empty($filterProps)) {
            $rsPropList = \CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $offersIblockId, 'PROPERTY_TYPE' => 'L', 'ACTIVE' => 'Y']);
            while ($arProp = $rsPropList->Fetch()) {
                $code = (string)$arProp['CODE'];
                $propId = (int)$arProp['ID'];
                if ($propId > 0 && $code !== $volumeCode && $code !== $formatCode && $code !== 'CML2_LINK' && isset($filterProps[$propId])) {
                    $otherProps[$propId] = $code;
                }
            }
        }

        $selectFields = ['ID', 'IBLOCK_ID', "PROPERTY_{$formatCode}", "PROPERTY_{$volumeCode}"];
        foreach ($otherProps as $code) {
            $selectFields[] = "PROPERTY_{$code}";
        }

        $rsOffers = \CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $offersIblockId, 'PROPERTY_CML2_LINK' => $productId, 'ACTIVE' => 'Y'], false, false, $selectFields);
        $meta = [];
        while ($arOffer = $rsOffers->Fetch()) {
            $offerId = (int)$arOffer['ID'];

            $formatXmlId = $this->enumValueResolver->resolveXmlId(
                $arOffer["PROPERTY_{$formatCode}_VALUE_XML_ID"] ?? null,
                isset($arOffer["PROPERTY_{$formatCode}_ENUM_ID"]) ? (int)$arOffer["PROPERTY_{$formatCode}_ENUM_ID"] : null,
                $formatEnumMap
            );

            $volumeXmlId = $this->enumValueResolver->resolveXmlId(
                $arOffer["PROPERTY_{$volumeCode}_VALUE_XML_ID"] ?? null,
                isset($arOffer["PROPERTY_{$volumeCode}_ENUM_ID"]) ? (int)$arOffer["PROPERTY_{$volumeCode}_ENUM_ID"] : null,
                array_map('strval', $volumeEnumMap)
            );

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

            $meta[$offerId] = ['id' => $offerId, 'width' => $formatParsed['width'] ?? null, 'height' => $formatParsed['height'] ?? null, 'volume' => $volumeParsed, 'props' => $props];
        }

        return $meta;
    }

    public function loadGroupPrices(array $offerIds): array
    {
        if (empty($offerIds)) {
            return [];
        }
        $pricesByGroup = [];
        $rsPrices = PriceTable::getList([
            'filter' => ['=PRODUCT_ID' => $offerIds],
            'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'QUANTITY_FROM'],
            'order' => ['PRODUCT_ID' => 'ASC', 'CATALOG_GROUP_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC'],
        ]);
        while ($ar = $rsPrices->fetch()) {
            $oid = (int)$ar['PRODUCT_ID'];
            $gid = (int)$ar['CATALOG_GROUP_ID'];
            if (!isset($pricesByGroup[$gid][$oid])) {
                $pricesByGroup[$gid][$oid] = (float)$ar['PRICE'];
            }
        }
        return $pricesByGroup;
    }

    public function loadGroupRangePrices(array $offerIds): array
    {
        if (empty($offerIds)) {
            return [];
        }
        $rangesByGroup = [];
        $rsPrices = PriceTable::getList([
            'filter' => ['=PRODUCT_ID' => $offerIds],
            'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'QUANTITY_FROM', 'QUANTITY_TO'],
            'order' => ['CATALOG_GROUP_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC', 'QUANTITY_TO' => 'ASC', 'PRODUCT_ID' => 'ASC'],
        ]);
        while ($ar = $rsPrices->fetch()) {
            $oid = (int)$ar['PRODUCT_ID'];
            $gid = (int)$ar['CATALOG_GROUP_ID'];
            $from = $ar['QUANTITY_FROM'] !== null ? (int)$ar['QUANTITY_FROM'] : null;
            $to = $ar['QUANTITY_TO'] !== null ? (int)$ar['QUANTITY_TO'] : null;
            $key = ($from === null ? '' : (string)$from) . '-' . ($to === null ? '' : (string)$to);
            $rangesByGroup[$gid][$key][] = ['offerId' => $oid, 'from' => $from, 'to' => $to, 'price' => (float)$ar['PRICE']];
        }
        return $rangesByGroup;
    }

    private function resolvePropId(int $iblockId, string $code): ?int
    {
        if ($code === '') {
            return null;
        }
        $rsProp = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code, 'ACTIVE' => 'Y']);
        if ($arProp = $rsProp->Fetch()) {
            return (int)$arProp['ID'];
        }
        return null;
    }

    private function loadVolumeEnumMap(?int $propId): array
    {
        $result = [];
        foreach ($this->enumValueResolver->loadEnumXmlMap($propId) as $enumId => $xmlId) {
            if (is_numeric($xmlId) || $xmlId === 'X') {
                $result[(int)$enumId] = $xmlId === 'X' ? 'X' : (int)$xmlId;
            }
        }
        return $result;
    }
}
