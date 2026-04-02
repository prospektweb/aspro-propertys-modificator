<?php

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;

class AdminHandler
{
    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if (!preg_match('#/bitrix/admin/iblock_element_(edit|admin)\.php$#', $scriptName)) {
            return;
        }

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? $_REQUEST['iblock_id'] ?? 0);
        if ($iblockId <= 0 || $iblockId !== Config::getProductsIblockId()) {
            return;
        }

        $customConfigCode = Config::getCustomConfigPropCode();
        if ($customConfigCode === '') {
            return;
        }

        $prop = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            'CODE'      => $customConfigCode,
            'ACTIVE'    => 'Y',
        ])->Fetch();

        if (!$prop) {
            return;
        }

        $payload = [
            'customConfigPropertyId' => (int)$prop['ID'],
            'customConfigPropertyCode' => $customConfigCode,
            'offersIblockId' => Config::getOffersIblockId(),
            'apiUrl' => '/bitrix/tools/prospektweb.propmodificator/admin_config.php',
            'sessid' => bitrix_sessid(),
            'volumePropCode' => Config::getVolumePropCode(),
            'formatPropCode' => Config::getFormatPropCode(),
        ];

        Asset::getInstance()->addCss('/bitrix/js/prospektweb.propmodificator/admin-builder.css');
        Asset::getInstance()->addJs('/bitrix/js/prospektweb.propmodificator/admin-builder.js');
        Asset::getInstance()->addString(
            '<script>window.pmodAdminConfig = ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
        );
    }
}
