<?php
/**
 * Установщик / деинсталлятор модуля prospekt.propmodificator
 *
 * Использование:
 *   /bitrix/admin/partner_modules.php — стандартный интерфейс Битрикс
 */

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;
use Prospekt\PropModificator\Config;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

class prospekt_propmodificator extends CModule
{
    public $MODULE_ID          = 'prospekt.propmodificator';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = 'N';
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION      = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME         = 'Модификатор свойств ТП (Аспро: Премьер)';
        $this->MODULE_DESCRIPTION  = 'Добавляет произвольный ввод формата и тиража на карточке товара с интерполяцией цены.';
        $this->PARTNER_NAME        = 'Prospekt';
        $this->PARTNER_URI         = 'https://prospektweb.ru';
    }

    // ─── Установка ────────────────────────────────────────────────────────────

    public function DoInstall()
    {
        global $APPLICATION;

        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);

        $this->InstallDB();
        $this->InstallFiles();
        $this->InstallEvents();

        $APPLICATION->IncludeAdminFile(
            'Установка модуля ' . $this->MODULE_NAME,
            __DIR__ . '/step.php'
        );
    }

    public function InstallDB()
    {
        // Определяем ID инфоблоков
        $offersIblockId   = $this->detectOffersIblockId();
        $productsIblockId = $this->detectProductsIblockId($offersIblockId);

        if ($offersIblockId) {
            COption::SetOptionString($this->MODULE_ID, 'OFFERS_IBLOCK_ID', $offersIblockId);
        }
        if ($productsIblockId) {
            COption::SetOptionString($this->MODULE_ID, 'PRODUCTS_IBLOCK_ID', $productsIblockId);
        }

        // Проверяем свойства в инфоблоке ТП
        $this->validateOffersProperties($offersIblockId);

        // Создаём свойства в инфоблоке товаров
        if ($productsIblockId) {
            $this->createProductProperties($productsIblockId);
        }

        return true;
    }

    public function InstallFiles()
    {
        // Копируем JS/CSS в публичную директорию Битрикс
        $srcDir  = __DIR__ . '/js';
        $destDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js/prospekt.propmodificator';

        if (is_dir($srcDir)) {
            CopyDirFiles($srcDir, $destDir, true, true);
        }

        return true;
    }

    public function InstallEvents()
    {
        $eventManager = EventManager::getInstance();

        $eventManager->registerEventHandlerCompatible(
            'sale',
            'OnBeforeBasketAdd',
            $this->MODULE_ID,
            'Prospekt\\PropModificator\\BasketHandler',
            'onBeforeBasketAdd'
        );

        $eventManager->registerEventHandlerCompatible(
            'sale',
            'OnBeforeSaleBasketItemSetFields',
            $this->MODULE_ID,
            'Prospekt\\PropModificator\\BasketHandler',
            'onBeforeSaleBasketItemSetFields'
        );

        return true;
    }

    // ─── Деинсталляция ────────────────────────────────────────────────────────

    public function DoUninstall()
    {
        global $APPLICATION;

        $this->UnInstallEvents();
        $this->UnInstallFiles();
        $this->UnInstallDB();

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            'Деинсталляция модуля ' . $this->MODULE_NAME,
            __DIR__ . '/unstep.php'
        );
    }

    public function UnInstallDB()
    {
        // Свойства товаров намеренно не удаляем — в них могут быть данные
        return true;
    }

    public function UnInstallFiles()
    {
        $destDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js/prospekt.propmodificator';
        if (is_dir($destDir)) {
            DeleteDirFilesEx($destDir);
        }

        return true;
    }

    public function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();

        $eventManager->unRegisterEventHandler(
            'sale',
            'OnBeforeBasketAdd',
            $this->MODULE_ID,
            'Prospekt\\PropModificator\\BasketHandler',
            'onBeforeBasketAdd'
        );

        $eventManager->unRegisterEventHandler(
            'sale',
            'OnBeforeSaleBasketItemSetFields',
            $this->MODULE_ID,
            'Prospekt\\PropModificator\\BasketHandler',
            'onBeforeSaleBasketItemSetFields'
        );

        return true;
    }

    // ─── Вспомогательные методы ───────────────────────────────────────────────

    /**
     * Автоопределение инфоблока торговых предложений.
     * Сначала пробуем найти инфоблок типа aspro_premier_catalog,
     * затем проверяем связь через CCatalogSKU.
     */
    private function detectOffersIblockId(): int
    {
        // Пробуем найти через CCatalogSKU для известного ID товарного инфоблока
        if (Loader::includeModule('catalog')) {
            $arSku = CCatalogSKU::GetInfoByProductIBlock(14);
            if (!empty($arSku['IBLOCK_ID'])) {
                return (int)$arSku['IBLOCK_ID'];
            }
        }

        // Поиск по типу инфоблока Аспро
        $rsIblock = CIBlock::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_TYPE_ID' => 'aspro_premier_catalog', 'ACTIVE' => 'Y']
        );
        if ($ar = $rsIblock->Fetch()) {
            // Берём первый попавшийся инфоблок ТП в типе каталога Аспро
            // Реальное определение зависит от структуры конкретного сайта
            return (int)$ar['ID'];
        }

        // Фолбэк — значение по умолчанию
        return 15;
    }

    /**
     * Определяем инфоблок товаров по инфоблоку ТП.
     */
    private function detectProductsIblockId(int $offersIblockId): int
    {
        if (Loader::includeModule('catalog')) {
            $arSku = CCatalogSKU::GetInfoByOfferIBlock($offersIblockId);
            if (!empty($arSku['PRODUCT_IBLOCK_ID'])) {
                return (int)$arSku['PRODUCT_IBLOCK_ID'];
            }
        }

        return 14;
    }

    /**
     * Проверяем наличие и валидность свойств CALC_PROP_FORMAT и CALC_PROP_VOLUME
     * в инфоблоке торговых предложений.
     */
    private function validateOffersProperties(int $offersIblockId): void
    {
        if (!Loader::includeModule('iblock')) {
            return;
        }

        $propCodes = ['CALC_PROP_FORMAT', 'CALC_PROP_VOLUME'];
        $issues    = [];

        foreach ($propCodes as $code) {
            $rsProp = CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $offersIblockId, 'CODE' => $code, 'ACTIVE' => 'Y']
            );
            $prop = $rsProp->Fetch();

            if (!$prop) {
                $issues[] = "Свойство {$code} не найдено в инфоблоке ТП (ID={$offersIblockId})";
                continue;
            }

            // Проверяем значения перечисления
            $rsEnum = CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC'],
                ['IBLOCK_ID' => $offersIblockId, 'CODE' => $code]
            );

            $valid = false;
            while ($arEnum = $rsEnum->Fetch()) {
                $xmlId = $arEnum['XML_ID'];
                if ($code === 'CALC_PROP_FORMAT') {
                    if (preg_match('/^\d+x\d+$/i', $xmlId)) {
                        $valid = true;
                        break;
                    }
                } else {
                    if (is_numeric($xmlId)) {
                        $valid = true;
                        break;
                    }
                }
            }

            if (!$valid) {
                $issues[] = "Свойство {$code}: не найдено ни одного значения с корректным XML_ID";
            }
        }

        // Сохраняем результат валидации для отображения в опциях
        COption::SetOptionString(
            $this->MODULE_ID,
            'VALIDATION_ISSUES',
            implode("\n", $issues)
        );
    }

    /**
     * Создаём SET_FORMAT и SET_VOLUME в инфоблоке товаров (если не существуют).
     */
    private function createProductProperties(int $productsIblockId): void
    {
        if (!Loader::includeModule('iblock')) {
            return;
        }

        $propsToCreate = [
            [
                'CODE'             => 'SET_FORMAT',
                'NAME'             => 'Настройки формата (для калькулятора)',
                'IBLOCK_ID'        => $productsIblockId,
                'PROPERTY_TYPE'    => 'S',
                'MULTIPLE'         => 'Y',
                'WITH_DESCRIPTION' => 'Y',
                'ACTIVE'           => 'Y',
                'SORT'             => 500,
                'FILTRABLE'        => 'N',
                'IS_REQUIRED'      => 'N',
                'HINT'             => 'Ключи: MIN_WIDTH, MAX_WIDTH, MIN_HEIGHT, MAX_HEIGHT, STEP. Значения — в описании.',
            ],
            [
                'CODE'             => 'SET_VOLUME',
                'NAME'             => 'Настройки тиража (для калькулятора)',
                'IBLOCK_ID'        => $productsIblockId,
                'PROPERTY_TYPE'    => 'S',
                'MULTIPLE'         => 'Y',
                'WITH_DESCRIPTION' => 'Y',
                'ACTIVE'           => 'Y',
                'SORT'             => 510,
                'FILTRABLE'        => 'N',
                'IS_REQUIRED'      => 'N',
                'HINT'             => 'Ключи: MIN, MAX, STEP. Значения — в описании.',
            ],
        ];

        $oProp = new CIBlockProperty();

        foreach ($propsToCreate as $arProp) {
            // Проверяем, не существует ли свойство уже
            $rsProp = CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $productsIblockId, 'CODE' => $arProp['CODE']]
            );

            if (!$rsProp->Fetch()) {
                $oProp->Add($arProp);
            }
        }
    }
}
