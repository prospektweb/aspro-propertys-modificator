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
    }

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
        $productsIblockId = (int)($_REQUEST['PRODUCTS_IBLOCK_ID'] ?? $this->detectProductsIblockId());

        if ($productsIblockId) {
            Option::set($this->MODULE_ID, 'PRODUCTS_IBLOCK_ID', $productsIblockId);
            $this->createProductProperties($productsIblockId);
        }

        Option::set($this->MODULE_ID, 'CUSTOM_CONFIG_PROP_CODE', 'PMOD_CUSTOM_CONFIG');

        return true;
    }

    public function installFiles(): bool
    {
        $srcDir  = __DIR__ . '/assets/js/prospektweb.propmodificator';
        $destDir = '/bitrix/js/prospektweb.propmodificator';

        if (is_dir(Application::getDocumentRoot() . '/bitrix/js')) {
            CopyDirFiles($srcDir, Application::getDocumentRoot() . $destDir, true, true);
        }

        $toolsSrc = __DIR__ . '/assets/tools/prospektweb.propmodificator';
        $toolsDest = Application::getDocumentRoot() . '/bitrix/tools/prospektweb.propmodificator';
        if (is_dir($toolsSrc)) {
            if (!is_dir($toolsDest)) {
                @mkdir($toolsDest, 0755, true);
            }
            if (is_dir($toolsDest)) {
                CopyDirFiles($toolsSrc, $toolsDest, true, true);
            }
        }

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
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\AdminHandler',
            'onProlog'
        );
    }

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
        if (!$saveData && Loader::includeModule('iblock')) {
            $productsIblockId = (int)Option::get($this->MODULE_ID, 'PRODUCTS_IBLOCK_ID', 0);
            if ($productsIblockId > 0) {
                $rsProp = CIBlockProperty::GetList([], ['IBLOCK_ID' => $productsIblockId, 'CODE' => 'PMOD_CUSTOM_CONFIG']);
                if ($arProp = $rsProp->Fetch()) {
                    CIBlockProperty::Delete($arProp['ID']);
                }
            }
        }

        return true;
    }

    public function uninstallFiles(): void
    {
        DeleteDirFilesEx('/bitrix/js/prospektweb.propmodificator');
        DeleteDirFilesEx('/bitrix/tools/prospektweb.propmodificator');
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

        return file_put_contents($includeFile, $content) !== false;
    }

    public function uninstallIncludeFile(): void
    {
        $includeFile = Application::getDocumentRoot() . '/bitrix/php_interface/include/prospektweb_propmodificator.php';
        if (file_exists($includeFile)) {
            @unlink($includeFile);
        }
    }

    public function uninstallEvents(): void
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            $this->MODULE_ID,
            'Prospektweb\\PropModificator\\AdminHandler',
            'onProlog'
        );
    }

    public function checkDependencies(): bool
    {
        if (Loader::includeModule('iblock')) {
            return true;
        }

        global $APPLICATION;
        $APPLICATION->ThrowException(Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_DEP_ERROR_IBLOCK'));
        return false;
    }

    public function detectProductsIblockId(): int
    {
        if (!Loader::includeModule('iblock')) {
            return 14;
        }

        $rsIblock = CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
        if ($ar = $rsIblock->Fetch()) {
            return (int)$ar['ID'];
        }

        return 14;
    }

    public function createProductProperties(int $productsIblockId): void
    {
        if (!Loader::includeModule('iblock') || $productsIblockId <= 0) {
            return;
        }

        $arProp = [
            'CODE'             => 'PMOD_CUSTOM_CONFIG',
            'NAME'             => 'Конструктор произвольных полей (JSON)',
            'IBLOCK_ID'        => $productsIblockId,
            'PROPERTY_TYPE'    => 'S',
            'USER_TYPE'        => 'HTML',
            'MULTIPLE'         => 'N',
            'WITH_DESCRIPTION' => 'N',
            'ACTIVE'           => 'Y',
            'SORT'             => 500,
            'FILTRABLE'        => 'N',
            'IS_REQUIRED'      => 'N',
            'HINT'             => 'JSON-конфиг конструктора полей. Редактируется UI-редактором модуля.',
        ];

        $rsProp = CIBlockProperty::GetList([], ['IBLOCK_ID' => $productsIblockId, 'CODE' => $arProp['CODE']]);
        if (!$rsProp->Fetch()) {
            $oProp = new CIBlockProperty();
            $oProp->Add($arProp);
        }
    }
}
