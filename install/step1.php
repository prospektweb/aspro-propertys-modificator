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
    </table>

    <input type="submit" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_BTN_INSTALL') ?>" class="adm-btn-save">
    &nbsp;
    <input type="button" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP1_BTN_CANCEL') ?>"
           onclick="window.location='/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>'">
</form>
