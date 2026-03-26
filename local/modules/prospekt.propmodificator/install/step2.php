<?php
/**
 * Шаг 2 установки: результат выполнения
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */
global $APPLICATION;

$exception = $APPLICATION->GetException();
?>
<?php if ($exception): ?>
    <div class="adm-info-message-wrap error">
        <div class="adm-info-message">
            <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP2_INSTALL_ERROR') ?><br>
            <?= htmlspecialchars($exception->GetString()) ?>
        </div>
    </div>
    <p>
        <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>">
            <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP2_BACK_TO_MODULES') ?>
        </a>
    </p>
<?php else: ?>
    <div class="adm-info-message-wrap success">
        <div class="adm-info-message">
            <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP2_INSTALL_SUCCESS') ?>
        </div>
    </div>
    <form method="post" action="<?= $APPLICATION->GetCurPage() ?>">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <input type="hidden" name="id" value="prospekt.propmodificator">
        <input type="hidden" name="install" value="Y">
        <input type="hidden" name="step" value="3">
        <input type="submit" value="<?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_STEP2_BTN_NEXT') ?>" class="adm-btn-save">
    </form>
<?php endif; ?>
