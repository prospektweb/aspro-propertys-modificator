<?php
/**
 * Шаг 3 установки: завершение
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;
?>
<div class="adm-info-message-wrap success">
    <div class="adm-info-message">
        <?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP3_SUCCESS') ?>
    </div>
</div>
<p>
    <a href="/bitrix/admin/settings.php?mid=prospektweb.propmodificator&amp;lang=<?= LANGUAGE_ID ?>">
        <?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP3_LINK_SETTINGS') ?>
    </a>
</p>
<p>
    <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>">
        <?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_STEP3_LINK_MODULES') ?>
    </a>
</p>
