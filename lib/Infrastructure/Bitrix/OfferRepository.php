<?php

namespace Prospektweb\PropModificator\Infrastructure\Bitrix;

use Bitrix\Catalog\PriceTable;
use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\Domain\PropertyBinding\PropertyBindingResolverInterface;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\FormatFieldModeHandler;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\VolumeFieldModeHandler;

class OfferRepository
{
    public function __construct(
        private ?PropertyBindingResolverInterface $propertyBindingResolver = null,
        private ?FormatFieldModeHandler $formatHandler = null,
        private ?VolumeFieldModeHandler $volumeHandler = null,
    ) {
        $this->propertyBindingResolver = $this->propertyBindingResolver ?? new BitrixPropertyBindingResolver();
        $this->formatHandler = $this->formatHandler ?? new FormatFieldModeHandler();
        $this->volumeHandler = $this->volumeHandler ?? new VolumeFieldModeHandler();
    }

    public function loadOfferMetadata(int $productId, ?array $filterProps): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return [];
        }

        $offersIblockId = Config::getOffersIblockId();
        $formatCode = $this->formatHandler->getPropertyCode();
        $volumeCode = $this->volumeHandler->getPropertyCode();

        $formatPropId = $this->propertyBindingResolver->resolvePropertyId($offersIblockId, $formatCode);
        $volumePropId = $this->propertyBindingResolver->resolvePropertyId($offersIblockId, $volumeCode);

        $volumeEnumMap = $this->loadVolumeEnumMap($volumePropId);
        $formatEnumMap = $this->propertyBindingResolver->loadEnumXmlMap($formatPropId);

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

            $formatEnumId = isset($arOffer["PROPERTY_{$formatCode}_ENUM_ID"]) ? (int)$arOffer["PROPERTY_{$formatCode}_ENUM_ID"] : null;
            $volumeEnumId = isset($arOffer["PROPERTY_{$volumeCode}_ENUM_ID"]) ? (int)$arOffer["PROPERTY_{$volumeCode}_ENUM_ID"] : null;

            $formatXmlId = $formatEnumId !== null ? ($formatEnumMap[$formatEnumId] ?? null) : null;
            $volumeXmlId = $volumeEnumId !== null ? (($volumeEnumMap[$volumeEnumId] ?? null) !== null ? (string)$volumeEnumMap[$volumeEnumId] : null) : null;

            $formatParsed = $formatXmlId ? $this->formatHandler->parseXmlId($formatXmlId) : null;
            $volumeParsed = $volumeXmlId ? $this->volumeHandler->parseXmlId($volumeXmlId) : null;
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

    private function loadVolumeEnumMap(?int $propId): array
    {
        $result = [];
        foreach ($this->propertyBindingResolver->loadEnumXmlMap($propId) as $enumId => $xmlId) {
            if (is_numeric($xmlId) || $xmlId === 'X') {
                $result[(int)$enumId] = $xmlId === 'X' ? 'X' : (int)$xmlId;
            }
        }
        return $result;
    }
}
