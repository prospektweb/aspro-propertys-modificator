<?php
/**
 * Шаг 2 деинсталляции: результат удаления
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

/** @global CMain $APPLICATION */
global $APPLICATION;

$exception = $APPLICATION->GetException();
$savedData = (isset($_REQUEST['save_data']) && $_REQUEST['save_data'] === 'Y');
?>
<?php if ($exception): ?>
    <div class="adm-info-message-wrap error">
        <div class="adm-info-message">
            <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_UNSTEP2_ERROR') ?><br>
            <?= htmlspecialchars($exception->GetString()) ?>
        </div>
    </div>
<?php else: ?>
    <div class="adm-info-message-wrap success">
        <div class="adm-info-message">
            <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_UNSTEP2_SUCCESS') ?>
            <br>
            <?php if ($savedData): ?>
                <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_UNSTEP2_PROPS_SAVED') ?>
            <?php else: ?>
                <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_UNSTEP2_PROPS_DELETED') ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<p>
    <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>">
        <?= Loc::getMessage('PROSPEKT_PROPMODIFICATOR_UNSTEP2_LINK_MODULES') ?>
    </a>
</p>
