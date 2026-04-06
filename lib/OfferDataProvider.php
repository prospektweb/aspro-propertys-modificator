<?php

namespace Prospektweb\PropModificator;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\RoundingTable;

/**
 * Loads offer-level Bitrix data required for frontend calculator bootstrap.
 *
 * Input: productId and module config codes.
 * Output: normalized array with offers, enums, groups, rounding and custom settings.
 */
class OfferDataProvider
{
    /** @return array<string,mixed>|null */
    public function loadForProduct(int $productId): ?array
    {
        $offersIblockId   = Config::getOffersIblockId();
        $formatPropCode   = Config::getFormatPropCode();
        $volumePropCode   = Config::getVolumePropCode();
        $customConfigCode = Config::getCustomConfigPropCode();

        $rsProduct = \CIBlockElement::GetByID($productId);
        $arProduct = $rsProduct->GetNextElement();

        $formatSettings = [];
        $volumeSettings = [];
        $customConfig   = [];

        if ($arProduct && $customConfigCode !== '') {
            $props = $arProduct->GetProperties([], []);
            $propPayload = $props[$customConfigCode] ?? null;
            $rawConfigValue = is_array($propPayload)
                ? ($propPayload['~VALUE'] ?? $propPayload['VALUE'] ?? null)
                : null;

            $customConfig = CustomConfig::parseFromPropertyValue($rawConfigValue);
            if (!empty($customConfig)) {
                $extracted = CustomConfig::extractCalculatorSettings($customConfig, $formatPropCode, $volumePropCode);
                $formatSettings = $extracted['formatSettings'];
                $volumeSettings = $extracted['volumeSettings'];
            }
        }

        if (empty($formatSettings) && empty($volumeSettings)) {
            return null;
        }

        $formatPropId = $this->resolvePropId($offersIblockId, $formatPropCode);
        $volumePropId = $this->resolvePropId($offersIblockId, $volumePropCode);

        $volumeEnumMap = $this->loadVolumeEnumMap($volumePropId);
        $formatEnumMap = $this->loadFormatEnumMap($formatPropId);
        $otherProps    = $this->loadOtherListProps($offersIblockId, $formatPropCode, $volumePropCode);

        $offersData = $this->loadOffers($productId, $offersIblockId, $formatPropCode, $volumePropCode, $formatEnumMap, $volumeEnumMap, $otherProps);
        $offers = $offersData['offers'];
        $offerIds = $offersData['offerIds'];

        if (!empty($offerIds)) {
            $rsPrices = PriceTable::getList([
                'filter' => ['=PRODUCT_ID' => $offerIds],
                'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'QUANTITY_FROM', 'QUANTITY_TO'],
                'order' => ['PRODUCT_ID' => 'ASC', 'CATALOG_GROUP_ID' => 'ASC', 'QUANTITY_FROM' => 'ASC'],
            ]);
            while ($arPrice = $rsPrices->fetch()) {
                $id = (int)$arPrice['PRODUCT_ID'];
                $gid = (int)$arPrice['CATALOG_GROUP_ID'];
                if (isset($offers[$id])) {
                    $offers[$id]['prices'][$gid][] = [
                        'from' => $arPrice['QUANTITY_FROM'] !== null ? (int)$arPrice['QUANTITY_FROM'] : null,
                        'to' => $arPrice['QUANTITY_TO'] !== null ? (int)$arPrice['QUANTITY_TO'] : null,
                        'price' => (float)$arPrice['PRICE'],
                    ];
                }
            }
        }

        return [
            'productId' => $productId,
            'formatPropId' => $formatPropId,
            'volumePropId' => $volumePropId,
            'formatPropCode' => $formatPropCode,
            'volumePropCode' => $volumePropCode,
            'formatSettings' => $formatSettings,
            'volumeSettings' => $volumeSettings,
            'offers' => array_values($offers),
            'volumeEnumMap' => $volumeEnumMap,
            'formatEnumMap' => $formatEnumMap,
            'catalogGroups' => $this->loadCatalogGroups(),
            'canBuyGroups' => $this->loadCanBuyGroups(),
            'allPropIds' => array_keys($otherProps),
            'skuPropCodeToId' => array_flip(array_filter(array_merge(
                $otherProps,
                [$formatPropId => $formatPropCode, $volumePropId => $volumePropCode]
            ))),
            'roundingRules' => $this->loadRoundingRules(),
            'customConfig' => $customConfig,
        ];
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
        if (!$propId) {
            return $result;
        }
        $rsEnum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propId]);
        while ($arEnum = $rsEnum->Fetch()) {
            $enumId = (int)$arEnum['ID'];
            $xmlId  = trim((string)($arEnum['XML_ID'] ?? ''));
            if ($enumId > 0 && (is_numeric($xmlId) || $xmlId === 'X')) {
                $result[$enumId] = $xmlId === 'X' ? 'X' : (int)$xmlId;
            }
        }
        return $result;
    }

    private function loadFormatEnumMap(?int $propId): array
    {
        $result = [];
        if (!$propId) {
            return $result;
        }
        $rsEnum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propId]);
        while ($arEnum = $rsEnum->Fetch()) {
            $enumId = (int)$arEnum['ID'];
            $xmlId  = trim((string)($arEnum['XML_ID'] ?? ''));
            if ($enumId > 0 && $xmlId !== '') {
                $result[$enumId] = $xmlId;
            }
        }
        return $result;
    }

    private function loadOtherListProps(int $offersIblockId, string $formatPropCode, string $volumePropCode): array
    {
        $otherProps = [];
        $rsPropList = \CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $offersIblockId, 'PROPERTY_TYPE' => 'L', 'ACTIVE' => 'Y']);
        while ($arProp = $rsPropList->Fetch()) {
            $code = (string)$arProp['CODE'];
            $propId = (int)$arProp['ID'];
            if ($propId > 0 && $code !== $volumePropCode && $code !== $formatPropCode && $code !== 'CML2_LINK') {
                $otherProps[$propId] = $code;
            }
        }
        return $otherProps;
    }

    private function loadOffers(int $productId, int $offersIblockId, string $formatPropCode, string $volumePropCode, array $formatEnumMap, array $volumeEnumMap, array $otherProps): array
    {
        $offers = [];
        $offerIds = [];

        $selectFields = ['ID', 'NAME', "PROPERTY_{$formatPropCode}", "PROPERTY_{$volumePropCode}"];
        foreach ($otherProps as $code) {
            $selectFields[] = "PROPERTY_{$code}";
        }

        $rsOffers = \CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $offersIblockId, 'PROPERTY_CML2_LINK' => $productId, 'ACTIVE' => 'Y'], false, false, $selectFields);
        while ($arOffer = $rsOffers->Fetch()) {
            $offerId = (int)$arOffer['ID'];

            $formatXmlId = $arOffer["PROPERTY_{$formatPropCode}_VALUE_XML_ID"] ?? null;
            if (empty($formatXmlId)) {
                $enumId = (int)($arOffer["PROPERTY_{$formatPropCode}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $formatXmlId = $formatEnumMap[$enumId] ?? null;
                }
            }

            $volumeXmlId = $arOffer["PROPERTY_{$volumePropCode}_VALUE_XML_ID"] ?? null;
            if (empty($volumeXmlId)) {
                $enumId = (int)($arOffer["PROPERTY_{$volumePropCode}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $volumeXmlId = $volumeEnumMap[$enumId] ?? null;
                    if ($volumeXmlId !== null) {
                        $volumeXmlId = (string)$volumeXmlId;
                    }
                }
            }

            $formatParsed = $formatXmlId ? PropertyValidator::parseFormatXmlId($formatXmlId) : null;
            $volumeParsed = $volumeXmlId ? PropertyValidator::parseVolumeXmlId($volumeXmlId) : null;

            $offers[$offerId] = [
                'id' => $offerId,
                'name' => $arOffer['NAME'],
                'width' => $formatParsed['width'] ?? null,
                'height' => $formatParsed['height'] ?? null,
                'volume' => $volumeParsed,
                'prices' => [],
                'props' => [],
            ];

            foreach ($otherProps as $propId => $code) {
                $enumId = (int)($arOffer["PROPERTY_{$code}_ENUM_ID"] ?? 0);
                if ($enumId > 0) {
                    $offers[$offerId]['props'][$propId] = $enumId;
                }
            }

            $offerIds[] = $offerId;
        }

        return ['offers' => $offers, 'offerIds' => $offerIds];
    }

    private function loadCatalogGroups(): array
    {
        $catalogGroups = [];
        try {
            $rs = GroupTable::getList(['select' => ['ID', 'NAME', 'BASE'], 'order' => ['ID' => 'ASC']]);
            while ($arGroup = $rs->fetch()) {
                $gid = (int)$arGroup['ID'];
                $catalogGroups[$gid] = ['id' => $gid, 'name' => (string)$arGroup['NAME'], 'base' => ($arGroup['BASE'] ?? 'N') === 'Y'];
            }
        } catch (\Throwable $e) {
            PageHandler::debugLog('Failed to load catalog groups: ' . $e->getMessage());
        }
        return $catalogGroups;
    }

    private function loadCanBuyGroups(): array
    {
        $canBuyGroupIds = [];
        global $USER;
        $userGroups = [];
        if (is_object($USER) && method_exists($USER, 'GetUserGroupArray')) {
            $userGroups = (array)$USER->GetUserGroupArray();
        }
        if (empty($userGroups)) {
            $userGroups = [2];
        }
        if (class_exists('CCatalogGroup')) {
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
                PageHandler::debugLog('Failed to load buy permissions for price groups: ' . $e->getMessage());
            }
        }
        return array_values(array_unique($canBuyGroupIds));
    }

    private function loadRoundingRules(): array
    {
        $roundingRules = [];
        try {
            $rs = RoundingTable::getList(['select' => ['CATALOG_GROUP_ID', 'PRICE', 'ROUND_TYPE', 'ROUND_PRECISION'], 'order' => ['CATALOG_GROUP_ID' => 'ASC', 'PRICE' => 'ASC']]);
            while ($ar = $rs->fetch()) {
                $gid = (int)$ar['CATALOG_GROUP_ID'];
                $roundingRules[$gid][] = [
                    'price' => (float)$ar['PRICE'],
                    'type' => (int)$ar['ROUND_TYPE'],
                    'precision' => (float)$ar['ROUND_PRECISION'],
                ];
            }
        } catch (\Throwable $e) {
            PageHandler::debugLog('Failed to load rounding rules: ' . $e->getMessage());
        }
        return $roundingRules;
    }
}
