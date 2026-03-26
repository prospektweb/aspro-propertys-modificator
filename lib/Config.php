<?php
/**
 * Хелпер конфигурации модуля prospektweb.propmodificator
 *
 * Централизованный доступ к настройкам модуля через COption.
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Config\Option;

class Config
{
    private const MODULE_ID = 'prospektweb.propmodificator';

    private static array $cache = [];

    /**
     * Получить значение опции.
     *
     * @param string $key     Код опции
     * @param mixed  $default Значение по умолчанию
     * @return string
     */
    public static function get(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, self::$cache)) {
            // Загружаем defaults из файла
            $arDefaultOptions = [];
            $defaultFile = __DIR__ . '/../default_option.php';
            if (file_exists($defaultFile)) {
                include $defaultFile;
            }
            $fallback = $arDefaultOptions[$key] ?? $default;

            self::$cache[$key] = \COption::GetOptionString(self::MODULE_ID, $key, $fallback);
        }

        return self::$cache[$key];
    }

    /**
     * Установить значение опции и сбросить кэш.
     */
    public static function set(string $key, string $value): void
    {
        \COption::SetOptionString(self::MODULE_ID, $key, $value);
        self::$cache[$key] = $value;
    }

    /**
     * ID инфоблока торговых предложений.
     */
    public static function getOffersIblockId(): int
    {
        return (int)self::get('OFFERS_IBLOCK_ID', '15');
    }

    /**
     * ID инфоблока товаров (родительский).
     */
    public static function getProductsIblockId(): int
    {
        return (int)self::get('PRODUCTS_IBLOCK_ID', '14');
    }

    /**
     * Символьный код свойства ФОРМАТ в инфоблоке ТП.
     */
    public static function getFormatPropCode(): string
    {
        return self::get('FORMAT_PROP_CODE', 'CALC_PROP_FORMAT');
    }

    /**
     * Символьный код свойства ТИРАЖ в инфоблоке ТП.
     */
    public static function getVolumePropCode(): string
    {
        return self::get('VOLUME_PROP_CODE', 'CALC_PROP_VOLUME');
    }

    /**
     * Символьный код свойства НАСТРОЙКИ ФОРМАТА в инфоблоке товаров.
     */
    public static function getSetFormatPropCode(): string
    {
        return self::get('SET_FORMAT_PROP_CODE', 'SET_FORMAT');
    }

    /**
     * Символьный код свойства НАСТРОЙКИ ТИРАЖА в инфоблоке товаров.
     */
    public static function getSetVolumePropCode(): string
    {
        return self::get('SET_VOLUME_PROP_CODE', 'SET_VOLUME');
    }

    /**
     * ID типа цены для интерполяции.
     */
    public static function getPriceTypeId(): int
    {
        return (int)self::get('PRICE_TYPE_ID', '1');
    }

    /**
     * ID модуля (константа).
     */
    public static function getModuleId(): string
    {
        return self::MODULE_ID;
    }
}
