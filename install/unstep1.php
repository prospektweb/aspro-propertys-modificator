<?php
/**
 * Шаг 1 деинсталляции: подтверждение удаления
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */
global $APPLICATION;
?>
<div class="adm-info-message-wrap warn">
    <div class="adm-info-message">
        <?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_UNSTEP1_WARNING') ?>
    </div>
</div>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="prospektweb.propmodificator">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">

    <table class="adm-detail-content-table edit-table">
        <tr>
            <td colspan="2">
                <label>
                    <input type="checkbox" name="save_data" value="Y" checked>
                    &nbsp;<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_UNSTEP1_SAVE_DATA') ?>
                </label>
            </td>
        </tr>
    </table>

    <input type="submit" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_UNSTEP1_BTN_DELETE') ?>" class="adm-btn">
    &nbsp;
    <input type="button" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_UNSTEP1_BTN_CANCEL') ?>"
           onclick="window.location='/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>'">
</form>
