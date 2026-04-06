<?php
/**
 * Валидатор XML_ID свойств торговых предложений.
 *
 * Проверяет, что значения перечислений свойств CALC_PROP_FORMAT и CALC_PROP_VOLUME
 * имеют корректные XML_ID (формат «WxH» и числовой тираж соответственно).
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\FormatFieldModeHandler;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\VolumeFieldModeHandler;

class PropertyValidator
{
    public static function isValidFormatXmlId(string $xmlId): bool
    {
        return (new FormatFieldModeHandler())->isValidXmlId($xmlId);
    }

    public static function parseFormatXmlId(string $xmlId): ?array
    {
        return (new FormatFieldModeHandler())->parseXmlId($xmlId);
    }

    public static function isValidVolumeXmlId(string $xmlId): bool
    {
        return (new VolumeFieldModeHandler())->isValidXmlId($xmlId);
    }

    public static function parseVolumeXmlId(string $xmlId): ?int
    {
        return (new VolumeFieldModeHandler())->parseXmlId($xmlId);
    }

    /**
     * Проверить все значения свойства FORMAT в инфоблоке ТП.
     *
     * @param int    $iblockId    ID инфоблока ТП
     * @param string $propCode    Код свойства (по умолчанию CALC_PROP_FORMAT)
     * @return array{valid: bool, issues: string[], values: array}
     */
    public static function validateFormatProperty(int $iblockId, string $propCode = 'CALC_PROP_FORMAT'): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['valid' => false, 'issues' => ['Модуль iblock не загружен'], 'values' => []];
        }

        $rsEnum = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode]
        );

        $issues = [];
        $values = [];

        if (!$rsEnum) {
            return ['valid' => false, 'issues' => ["Свойство {$propCode} не найдено"], 'values' => []];
        }

        while ($arEnum = $rsEnum->Fetch()) {
            $xmlId = $arEnum['XML_ID'];

            if ($xmlId === 'X') {
                continue;
            }

            if (!self::isValidFormatXmlId($xmlId)) {
                $issues[] = "Некорректный XML_ID «{$xmlId}» (ожидается формат ШxВ, например «210x297»)";
                continue;
            }

            $parsed = self::parseFormatXmlId($xmlId);
            $values[$arEnum['ID']] = array_merge($arEnum, $parsed ?? []);
        }

        return [
            'valid'  => empty($issues) && !empty($values),
            'issues' => $issues,
            'values' => $values,
        ];
    }

    /**
     * Проверить все значения свойства VOLUME в инфоблоке ТП.
     *
     * @param int    $iblockId    ID инфоблока ТП
     * @param string $propCode    Код свойства (по умолчанию CALC_PROP_VOLUME)
     * @return array{valid: bool, issues: string[], values: array}
     */
    public static function validateVolumeProperty(int $iblockId, string $propCode = 'CALC_PROP_VOLUME'): array
    {
        if (!Loader::includeModule('iblock')) {
            return ['valid' => false, 'issues' => ['Модуль iblock не загружен'], 'values' => []];
        }

        $rsEnum = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propCode]
        );

        $issues = [];
        $values = [];

        if (!$rsEnum) {
            return ['valid' => false, 'issues' => ["Свойство {$propCode} не найдено"], 'values' => []];
        }

        while ($arEnum = $rsEnum->Fetch()) {
            $xmlId = $arEnum['XML_ID'];

            if ($xmlId === 'X') {
                continue;
            }

            if (!self::isValidVolumeXmlId($xmlId)) {
                $issues[] = "Некорректный XML_ID «{$xmlId}» (ожидается число, например «1000»)";
                continue;
            }

            $values[$arEnum['ID']] = array_merge($arEnum, ['volume' => (int)$xmlId]);
        }

        return [
            'valid'  => empty($issues) && !empty($values),
            'issues' => $issues,
            'values' => $values,
        ];
    }
}
