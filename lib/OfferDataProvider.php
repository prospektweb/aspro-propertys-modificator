<?php

namespace Prospektweb\PropModificator;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\RoundingTable;
use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;
use Prospektweb\PropModificator\Domain\PropertyBinding\PropertyBindingResolverInterface;
use Prospektweb\PropModificator\Infrastructure\Bitrix\BitrixPropertyBindingResolver;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\FormatFieldModeHandler;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\VolumeFieldModeHandler;

/**
 * Loads offer-level Bitrix data required for frontend calculator bootstrap.
 *
 * Input: productId and module config codes.
 * Output: normalized array with offers, enums, groups, rounding and custom settings.
 */
class OfferDataProvider
{
    public function __construct(
        private ?PropertyBindingResolverInterface $propertyBindingResolver = null,
        private ?ProductConfigReader $productConfigReader = null,
        private ?FormatFieldModeHandler $formatHandler = null,
        private ?VolumeFieldModeHandler $volumeHandler = null,
    ) {
        $this->propertyBindingResolver = $this->propertyBindingResolver ?? new BitrixPropertyBindingResolver();
        $this->productConfigReader = $this->productConfigReader ?? new ProductConfigReader();
        $this->formatHandler = $this->formatHandler ?? new FormatFieldModeHandler();
        $this->volumeHandler = $this->volumeHandler ?? new VolumeFieldModeHandler();
    }

    /** @return array<string,mixed>|null */
    public function loadForProduct(int $productId): ?array
    {
        $offersIblockId = Config::getOffersIblockId();
        $formatPropCode = $this->formatHandler->getPropertyCode();
        $volumePropCode = $this->volumeHandler->getPropertyCode();

        $settings = $this->productConfigReader->readByProductId($productId);
        $formatSettings = $settings['formatSettings'];
        $volumeSettings = $settings['volumeSettings'];
        $customConfig = $settings['customConfig'];

        if (empty($formatSettings) && empty($volumeSettings)) {
            return null;
        }

        $formatPropId = $this->propertyBindingResolver->resolvePropertyId($offersIblockId, $formatPropCode);
        $volumePropId = $this->propertyBindingResolver->resolvePropertyId($offersIblockId, $volumePropCode);

        $volumeEnumMap = $this->loadVolumeEnumMap($volumePropId);
        $formatEnumMap = $this->propertyBindingResolver->loadEnumXmlMap($formatPropId);
        $otherProps = $this->loadOtherListProps($offersIblockId, $formatPropCode, $volumePropCode);
        $skuPropsEnumMap = $this->loadSkuPropsEnumMap($formatPropId, $volumePropId, $otherProps);

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
            'skuPropsEnumMap' => $skuPropsEnumMap,
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

    /**
     * @param array<int,string> $otherProps
     * @return array<int,array<int,string>>
     */
    private function loadSkuPropsEnumMap(?int $formatPropId, ?int $volumePropId, array $otherProps): array
    {
        $result = [];
        $propIds = array_unique(array_filter(array_merge(
            [$formatPropId, $volumePropId],
            array_keys($otherProps)
        )));

        foreach ($propIds as $propIdRaw) {
            $propId = (int)$propIdRaw;
            if ($propId <= 0) {
                continue;
            }
            $enumMap = $this->propertyBindingResolver->loadEnumXmlMap($propId);
            if (!empty($enumMap)) {
                $result[$propId] = $enumMap;
            }
        }

        return $result;
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

            $formatEnumId = isset($arOffer["PROPERTY_{$formatPropCode}_ENUM_ID"]) ? (int)$arOffer["PROPERTY_{$formatPropCode}_ENUM_ID"] : null;
            $volumeEnumId = isset($arOffer["PROPERTY_{$volumePropCode}_ENUM_ID"]) ? (int)$arOffer["PROPERTY_{$volumePropCode}_ENUM_ID"] : null;

            $formatXmlId = $formatEnumId !== null ? ($formatEnumMap[$formatEnumId] ?? null) : null;
            $volumeXmlId = $volumeEnumId !== null ? (($volumeEnumMap[$volumeEnumId] ?? null) !== null ? (string)$volumeEnumMap[$volumeEnumId] : null) : null;

            $formatParsed = $formatXmlId ? $this->formatHandler->parseXmlId($formatXmlId) : null;
            $volumeParsed = $volumeXmlId ? $this->volumeHandler->parseXmlId($volumeXmlId) : null;

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
