<?php
/**
 * Валидатор XML_ID свойств торговых предложений.
 *
 * Проверяет, что значения перечислений свойств CALC_PROP_FORMAT и CALC_PROP_VOLUME
 * имеют корректные XML_ID (формат «WxH» и числовой тираж соответственно).
 */

namespace Prospekt\PropModificator;

use Bitrix\Main\Loader;

class PropertyValidator
{
    /**
     * Проверить XML_ID значения свойства FORMAT.
     * Допустимый формат: «210x297», «100x148» и т.п. (ШxВ, только цифры).
     *
     * @param string $xmlId
     * @return bool
     */
    public static function isValidFormatXmlId(string $xmlId): bool
    {
        return (bool)preg_match('/^\d+x\d+$/i', $xmlId);
    }

    /**
     * Разобрать XML_ID формата в массив ['width' => int, 'height' => int].
     *
     * @param string $xmlId  например «210x297»
     * @return array|null
     */
    public static function parseFormatXmlId(string $xmlId): ?array
    {
        if (!self::isValidFormatXmlId($xmlId)) {
            return null;
        }

        [$w, $h] = explode('x', strtolower($xmlId));

        return ['width' => (int)$w, 'height' => (int)$h];
    }

    /**
     * Проверить XML_ID значения свойства VOLUME.
     * Допустимый формат: числовая строка («100», «1000», «99999»).
     *
     * @param string $xmlId
     * @return bool
     */
    public static function isValidVolumeXmlId(string $xmlId): bool
    {
        return is_numeric($xmlId) && (int)$xmlId > 0;
    }

    /**
     * Разобрать XML_ID тиража в целое число.
     *
     * @param string $xmlId  например «1000»
     * @return int|null
     */
    public static function parseVolumeXmlId(string $xmlId): ?int
    {
        if (!self::isValidVolumeXmlId($xmlId)) {
            return null;
        }

        return (int)$xmlId;
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

            if (!self::isValidFormatXmlId($xmlId)) {
                $issues[] = "Некорректный XML_ID «{$xmlId}» (ожидается формат ШxВ, например «210x297»)";
                continue;
            }

            $parsed = self::parseFormatXmlId($xmlId);
            $values[$arEnum['ID']] = array_merge($arEnum, $parsed);
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
