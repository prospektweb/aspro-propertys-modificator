<?php
/**
 * Шаг 1 установки: выбор инфоблоков
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/** @var prospekt_propmodificator $module */
global $APPLICATION;

$offersIblockId   = $module->detectOffersIblockId();
$productsIblockId = $module->detectProductsIblockId($offersIblockId);

// Список инфоблоков для выпадающего списка
$iblockList = [];
if (Loader::includeModule('iblock')) {
    $rs = CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
    while ($ar = $rs->Fetch()) {
        $iblockList[] = $ar;
    }
}

// Проверяем свойства ТП
$validationIssues = $module->validateOffersProperties($offersIblockId);
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospekt.propmodificator">
    <input type="hidden" name="install" value="Y">
    <input type="hidden" name="step" value="2">

    <table class="adm-detail-content-table edit-table">
        <tr class="heading">
            <td colspan="2"><?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_SELECT_IBLOCKS') ?></td>
        </tr>
        <tr>
            <td width="40%" class="adm-detail-content-cell-l">
                <b><?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_OFFERS_IBLOCK') ?></b><br>
                <small><?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_OFFERS_IBLOCK_HINT') ?></small>
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
                <b><?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_PRODUCTS_IBLOCK') ?></b><br>
                <small><?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_PRODUCTS_IBLOCK_HINT') ?></small>
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
            <td colspan="2"><?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_VALIDATION_WARNINGS') ?></td>
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
                        <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_VALIDATION_OK') ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php endif; ?>
    </table>

    <input type="submit" value="<?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_BTN_INSTALL') ?>" class="adm-btn-save">
    &nbsp;
    <input type="button" value="<?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP1_BTN_CANCEL') ?>"
           onclick="window.location='/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>'">
</form>
