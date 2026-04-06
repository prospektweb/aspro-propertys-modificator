<?php
/**
 * Шаг 1 установки: выбор инфоблока товаров
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

global $APPLICATION;

$iblockList = [];
if (Loader::includeModule('iblock')) {
    $rs = CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
    while ($ar = $rs->Fetch()) {
        $iblockList[] = $ar;
    }
}

$productsIblockId = 0;
if (!empty($iblockList)) {
    $productsIblockId = (int)$iblockList[0]['ID'];
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
            <td class="adm-detail-content-cell-l" width="40%">
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
