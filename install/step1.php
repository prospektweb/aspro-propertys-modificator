<?php
/**
 * Шаг 1 установки: выбор инфоблоков
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

global $APPLICATION;

// Список инфоблоков для выпадающего списка
$iblockList = [];
if (Loader::includeModule('iblock')) {
    $rs = CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
    while ($ar = $rs->Fetch()) {
        $iblockList[] = $ar;
    }
}

// Автоопределение инфоблока торговых предложений
$offersIblockId = 0;
if (Loader::includeModule('catalog')) {
    $rsCatalog = \Bitrix\Catalog\CatalogIblockTable::getList([
        'select' => ['IBLOCK_ID', 'PRODUCT_IBLOCK_ID'],
        'filter' => ['!=PRODUCT_IBLOCK_ID' => 0],
        'limit'  => 1,
    ]);
    if ($arCatalog = $rsCatalog->fetch()) {
        $offersIblockId = (int)$arCatalog['IBLOCK_ID'];
    }
}
if (!$offersIblockId && Loader::includeModule('iblock')) {
    $rsIblock = CIBlock::GetList(
        ['SORT' => 'ASC'],
        ['IBLOCK_TYPE_ID' => 'aspro_premier_catalog', 'ACTIVE' => 'Y']
    );
    if ($ar = $rsIblock->Fetch()) {
        $offersIblockId = (int)$ar['ID'];
    }
}

// Автоопределение инфоблока товаров по инфоблоку ТП
$productsIblockId = 0;
if ($offersIblockId > 0 && Loader::includeModule('catalog')) {
    $arSku = CCatalogSKU::GetInfoByOfferIBlock($offersIblockId);
    if (!empty($arSku['PRODUCT_IBLOCK_ID'])) {
        $productsIblockId = (int)$arSku['PRODUCT_IBLOCK_ID'];
    }
}

// Проверяем наличие свойств CALC_PROP_FORMAT и CALC_PROP_VOLUME в инфоблоке ТП
$validationIssues = [];
if (Loader::includeModule('iblock') && $offersIblockId > 0) {
    foreach (['CALC_PROP_FORMAT', 'CALC_PROP_VOLUME'] as $code) {
        $rsProp = CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $offersIblockId, 'CODE' => $code, 'ACTIVE' => 'Y']
        );
        $prop = $rsProp->Fetch();

        if (!$prop) {
            $validationIssues[] = "Свойство {$code} не найдено в инфоблоке ТП (ID={$offersIblockId})";
            continue;
        }

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
            $validationIssues[] = "Свойство {$code}: не найдено ни одного значения с корректным XML_ID";
        }
    }
}
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.propmodificator">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_SELECT_IBLOCKS') ?></td>
        </tr>
        <tr>
            <td width="40%" class="adm-detail-content-cell-l">
                <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_OFFERS_IBLOCK') ?></b><br>
                <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_OFFERS_IBLOCK_HINT') ?></small>
            </td>
            <td class="adm-detail-content-cell-r">
                <select name="OFFERS_IBLOCK_ID">
                    <?php foreach ($iblockList as $iblock): ?>
                        <option value="<?= (int)$iblock['ID'] ?>"
                            <?= ((int)$iblock['ID'] === $offersIblockId ? ' selected' : '') ?>>
                            [<?= (int)$iblock['ID'] ?>] <?= htmlspecialchars($iblock['NAME']) ?>
                            (<?= htmlspecialchars($iblock['IBLOCK_TYPE_ID']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <td class="adm-detail-content-cell-l">
                <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_PRODUCTS_IBLOCK') ?></b><br>
                <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_PRODUCTS_IBLOCK_HINT') ?></small>
            </td>
            <td class="adm-detail-content-cell-r">
                <select name="PRODUCTS_IBLOCK_ID">
                    <?php foreach ($iblockList as $iblock): ?>
                        <option value="<?= (int)$iblock['ID'] ?>"
                            <?= ((int)$iblock['ID'] === $productsIblockId ? ' selected' : '') ?>>
                            [<?= (int)$iblock['ID'] ?>] <?= htmlspecialchars($iblock['NAME']) ?>
                            (<?= htmlspecialchars($iblock['IBLOCK_TYPE_ID']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php if (!empty($validationIssues)): ?>
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_VALIDATION_WARNINGS') ?></td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="adm-info-message-wrap warn">
                    <div class="adm-info-message">
                        <?php foreach ($validationIssues as $issue): ?>
                            <p><?= htmlspecialchars($issue) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php else: ?>
        <tr>
            <td colspan="2">
                <div class="adm-info-message-wrap success">
                    <div class="adm-info-message">
                        <?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_VALIDATION_OK') ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php endif; ?>
    </table>

    <input type="submit" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_BTN_INSTALL') ?>" class="adm-btn-save">
    &nbsp;
    <input type="button" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_BTN_CANCEL') ?>"
           onclick="window.location='/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>'">
</form>
