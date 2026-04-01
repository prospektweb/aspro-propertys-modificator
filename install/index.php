<?php
/**
 * Установщик / деинсталлятор модуля prospektweb.propmodificator
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

class prospektweb_propmodificator extends CModule
{
    public $MODULE_ID          = 'prospektweb.propmodificator';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = 'N';
    public $PARTNER_NAME;
    public $PARTNER_URI;

    /** @var string */
    private $modulePath;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION      = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME         = Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_MODULE_NAME');
        $this->MODULE_DESCRIPTION  = Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_MODULE_DESCRIPTION');
        $this->PARTNER_NAME        = 'PROSPEKT-WEB';
        $this->PARTNER_URI         = 'https://prospektweb.ru';
        $this->modulePath          = dirname(__DIR__);
    }

    // ─── Установка ────────────────────────────────────────────────────────────

    public function DoInstall()
    {
        global $APPLICATION;

        if (!$this->checkDependencies()) {
            return false;
        }

        $step = (int)($_REQUEST['step'] ?? 1);

        switch ($step) {
            case 2:
                ModuleManager::registerModule($this->MODULE_ID);
                Loader::includeModule($this->MODULE_ID);

                $this->installDB();
                $this->installFiles();
                $this->installFooter();
                $this->installEvents();

                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_INSTALL_TITLE'),
                    __DIR__ . '/step2.php'
                );
                break;

            case 3:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_INSTALL_TITLE'),
                    __DIR__ . '/step3.php'
                );
                break;

            default:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_INSTALL_TITLE'),
                    __DIR__ . '/step1.php'
                );
                break;
        }

        return true;
    }

    public function installDB(): bool
    {
        $offersIblockId   = (int)($_REQUEST['OFFERS_IBLOCK_ID'] ?? $this->detectOffersIblockId());
        $productsIblockId = (int)($_REQUEST['PRODUCTS_IBLOCK_ID'] ?? $this->detectProductsIblockId($offersIblockId));

        if ($offersIblockId) {
            Option::set($this->MODULE_ID, 'OFFERS_IBLOCK_ID', $offersIblockId);
        }
        if ($productsIblockId) {
            Option::set($this->MODULE_ID, 'PRODUCTS_IBLOCK_ID', $productsIblockId);
        }

        $this->validateOffersProperties($offersIblockId);

        if ($productsIblockId) {
            $this->createProductProperties($productsIblockId);
        }

        return true;
    }

    public function installFiles(): bool
    {
        // JS/CSS ассеты → /bitrix/js/prospektweb.propmodificator/
        $srcDir  = __DIR__ . '/assets/js/prospektweb.propmodificator';
        $destDir = '/bitrix/js/prospektweb.propmodificator';

        if (is_dir(Application::getDocumentRoot() . '/bitrix/js')) {
            CopyDirFiles($srcDir, Application::getDocumentRoot() . $destDir, true, true);
        }

        // AJAX-роутер → /ajax/prospektweb.propmodificator/
        $ajaxSrc  = __DIR__ . '/assets/ajax/prospektweb.propmodificator';
        $ajaxDest = Application::getDocumentRoot() . '/ajax/prospektweb.propmodificator';

        if (is_dir($ajaxSrc)) {
            if (!is_dir($ajaxDest)) {
                @mkdir($ajaxDest, 0755, true);
            }
            if (is_dir($ajaxDest)) {
                CopyDirFiles($ajaxSrc, $ajaxDest, true, true);
            }
        }

        // Aspro local overrides (копия шаблонных файлов в /local/templates/aspro-premier)
        $this->installAsproLocalOverrides();

        return true;
    }

    public function installFooter(): bool
    {
        $eventManager = EventManager::getInstance();

        $eventManager->registerEventHandler(
            'main',
            'OnEpilog',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\PageHandler',
            'onEpilog'
        );

        $this->installIncludeFile();

        return true;
    }

    public function installEvents(): void
    {
        $eventManager = EventManager::getInstance();

        $eventManager->registerEventHandler(
            'sale',
            'OnBeforeBasketAdd',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\BasketHandler',
            'onBeforeBasketAdd'
        );

        $eventManager->registerEventHandler(
            'sale',
            'OnBeforeSaleBasketItemSetFields',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\BasketHandler',
            'onBeforeSaleBasketItemSetFields'
        );
    }

    // ─── Деинсталляция ────────────────────────────────────────────────────────

    public function DoUninstall()
    {
        global $APPLICATION;

        $step = (int)($_REQUEST['step'] ?? 1);

        switch ($step) {
            case 2:
                $saveData = (isset($_REQUEST['save_data']) && $_REQUEST['save_data'] === 'Y');

                $this->uninstallEvents();
                $this->uninstallFooter();
                $this->uninstallFiles();
                $this->uninstallDB($saveData);

                ModuleManager::unRegisterModule($this->MODULE_ID);

                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_UNINSTALL_TITLE'),
                    __DIR__ . '/unstep2.php'
                );
                break;

            default:
                $APPLICATION->IncludeAdminFile(
                    Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_UNINSTALL_TITLE'),
                    __DIR__ . '/unstep1.php'
                );
                break;
        }
    }

    public function uninstallDB(bool $saveData = true): bool
    {
        if (!$saveData) {
            if (Loader::includeModule('iblock')) {
                $productsIblockId = (int)Option::get($this->MODULE_ID, 'PRODUCTS_IBLOCK_ID', 0);
                if ($productsIblockId > 0) {
                    foreach (['SET_FORMAT', 'SET_VOLUME'] as $code) {
                        $rsProp = CIBlockProperty::GetList(
                            [],
                            ['IBLOCK_ID' => $productsIblockId, 'CODE' => $code]
                        );
                        if ($arProp = $rsProp->Fetch()) {
                            CIBlockProperty::Delete($arProp['ID']);
                        }
                    }
                }
            }
        }

        return true;
    }

    public function uninstallFiles(): void
    {
        $this->uninstallAsproLocalOverrides();
        DeleteDirFilesEx('/bitrix/js/prospektweb.propmodificator');
        DeleteDirFilesEx('/ajax/prospektweb.propmodificator');
    }

    /**
     * Копирует ключевые файлы шаблона Аспро из /bitrix/templates в /local/templates
     * для безопасного override без правки vendor-файлов.
     */
    private function installAsproLocalOverrides(): void
    {
        $docRoot = Application::getDocumentRoot();
        $pairs = [
            '/bitrix/templates/aspro-premier/js/select_offer_func.js'
                => '/local/templates/aspro-premier/js/select_offer_func.js',
            '/bitrix/templates/aspro-premier/ajax/js_item_detail.php'
                => '/local/templates/aspro-premier/ajax/js_item_detail.php',
        ];

        foreach ($pairs as $srcRel => $dstRel) {
            $src = $docRoot . $srcRel;
            $dst = $docRoot . $dstRel;
            $marker = $dst . '.pmod_installed';

            if (!file_exists($src)) {
                continue;
            }

            if (!is_dir(dirname($dst))) {
                @mkdir(dirname($dst), 0755, true);
            }
            if (!is_dir(dirname($dst))) {
                continue;
            }

            // Не затираем пользовательские override-файлы.
            if (!file_exists($dst)) {
                @copy($src, $dst);
                if (file_exists($dst)) {
                    @file_put_contents(
                        $marker,
                        'Installed by prospektweb.propmodificator at ' . date('c')
                    );
                }
            }
        }
    }

    /**
     * Удаляет только те override-файлы Аспро в /local/templates,
     * которые были скопированы установщиком модуля (по marker-файлу).
     */
    private function uninstallAsproLocalOverrides(): void
    {
        $docRoot = Application::getDocumentRoot();
        $targets = [
            '/local/templates/aspro-premier/js/select_offer_func.js',
            '/local/templates/aspro-premier/ajax/js_item_detail.php',
        ];

        foreach ($targets as $dstRel) {
            $dst = $docRoot . $dstRel;
            $marker = $dst . '.pmod_installed';
            if (file_exists($marker)) {
                if (file_exists($dst)) {
                    @unlink($dst);
                }
                @unlink($marker);
            }
        }
    }

    public function uninstallFooter(): void
    {
        $eventManager = EventManager::getInstance();

        $eventManager->unRegisterEventHandler(
            'main',
            'OnEpilog',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\PageHandler',
            'onEpilog'
        );

        // Also clean up the old OnEpilogHtml handler if it was registered by a previous install
        $eventManager->unRegisterEventHandler(
            'main',
            'OnEpilogHtml',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\PageHandler',
            'onEpilogHtml'
        );

        $this->uninstallIncludeFile();
    }

    public function installIncludeFile(): bool
    {
        $includeDir  = Application::getDocumentRoot() . '/bitrix/php_interface/include';
        $includeFile = $includeDir . '/prospektweb_propmodificator.php';

        if (!is_dir($includeDir)) {
            @mkdir($includeDir, 0755, true);
        }

        if (!is_dir($includeDir)) {
            return false;
        }

        $content = <<<'PHP'
<?php
// Auto-created by prospektweb.propmodificator installer. Do not edit manually.
// Registers the page-handler event so the module works without init.php.
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    return;
}

\Bitrix\Main\EventManager::getInstance()->addEventHandler(
    'main',
    'OnEpilog',
    static function () {
        if (!\Bitrix\Main\Loader::includeModule('prospektweb.propmodificator')) {
            return;
        }
        $f = \Bitrix\Main\Application::getDocumentRoot()
            . \getLocalPath('modules/prospektweb.propmodificator/template_include.php');
        if (file_exists($f)) {
            include_once $f;
        }
    }
);
PHP;

        $result = file_put_contents($includeFile, $content);

        return $result !== false;
    }

    public function uninstallIncludeFile(): void
    {
        $includeFile = Application::getDocumentRoot()
            . '/bitrix/php_interface/include/prospektweb_propmodificator.php';

        if (file_exists($includeFile)) {
            @unlink($includeFile);
        }
    }

    public function uninstallEvents(): void
    {
        $eventManager = EventManager::getInstance();

        $eventManager->unRegisterEventHandler(
            'sale',
            'OnBeforeBasketAdd',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\BasketHandler',
            'onBeforeBasketAdd'
        );

        $eventManager->unRegisterEventHandler(
            'sale',
            'OnBeforeSaleBasketItemSetFields',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\BasketHandler',
            'onBeforeSaleBasketItemSetFields'
        );
    }

    // ─── Проверка зависимостей ────────────────────────────────────────────────

    public function checkDependencies(): bool
    {
        $errors = [];

        if (!Loader::includeModule('iblock')) {
            $errors[] = Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_DEP_ERROR_IBLOCK');
        }
        if (!Loader::includeModule('catalog')) {
            $errors[] = Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_DEP_ERROR_CATALOG');
        }
        if (!Loader::includeModule('sale')) {
            $errors[] = Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_DEP_ERROR_SALE');
        }

        if (!empty($errors)) {
            global $APPLICATION;
            $APPLICATION->ThrowException(implode('<br>', $errors));
            return false;
        }

        return true;
    }

    // ─── Вспомогательные методы ───────────────────────────────────────────────

    /**
     * Автоопределение инфоблока торговых предложений.
     */
    public function detectOffersIblockId(): int
    {
        if (Loader::includeModule('catalog')) {
            $arSku = CCatalogSKU::GetInfoByProductIBlock(14);
            if (!empty($arSku['IBLOCK_ID'])) {
                return (int)$arSku['IBLOCK_ID'];
            }
        }

        $rsIblock = CIBlock::GetList(
            ['SORT' => 'ASC'],
            ['IBLOCK_TYPE_ID' => 'aspro_premier_catalog', 'ACTIVE' => 'Y']
        );
        if ($ar = $rsIblock->Fetch()) {
            return (int)$ar['ID'];
        }

        return 15;
    }

    /**
     * Определяем инфоблок товаров по инфоблоку ТП.
     */
    public function detectProductsIblockId(int $offersIblockId): int
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
     * Если значение-маркер «X» отсутствует в перечислении свойства — создаём его автоматически.
     */
    public function validateOffersProperties(int $offersIblockId): array
    {
        $issues = [];

        if (!Loader::includeModule('iblock') || $offersIblockId <= 0) {
            return $issues;
        }

        $propCodes = ['CALC_PROP_FORMAT', 'CALC_PROP_VOLUME'];

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

            $propId = (int)$prop['ID'];

            $rsEnum = CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC'],
                ['IBLOCK_ID' => $offersIblockId, 'CODE' => $code]
            );

            $valid    = false;
            $hasXmark = false;
            while ($arEnum = $rsEnum->Fetch()) {
                $xmlId = $arEnum['XML_ID'];
                if ($xmlId === 'X') {
                    $hasXmark = true;
                } elseif ($code === 'CALC_PROP_FORMAT') {
                    if (preg_match('/^\d+x\d+$/i', $xmlId)) {
                        $valid = true;
                    }
                } else {
                    if (is_numeric($xmlId)) {
                        $valid = true;
                    }
                }
                // Прерываем перебор как только нашли оба флага
                if ($valid && $hasXmark) {
                    break;
                }
            }

            if (!$valid) {
                $issues[] = "Свойство {$code}: не найдено ни одного значения с корректным XML_ID";
            }

            // Если маркер произвольного значения «X» отсутствует — создаём его
            if (!$hasXmark && $propId > 0) {
                $oEnum = new CIBlockPropertyEnum();
                $oEnum->Add([
                    'PROPERTY_ID' => $propId,
                    'VALUE'       => 'Произвольное',
                    'XML_ID'      => 'X',
                    'SORT'        => 999,
                    'DEF'         => 'N',
                ]);
            }
        }

        Option::set($this->MODULE_ID, 'VALIDATION_ISSUES', implode("\n", $issues));

        return $issues;
    }

    /**
     * Создаём SET_FORMAT и SET_VOLUME в инфоблоке товаров (если не существуют).
     */
    public function createProductProperties(int $productsIblockId): void
    {
        if (!Loader::includeModule('iblock') || $productsIblockId <= 0) {
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
