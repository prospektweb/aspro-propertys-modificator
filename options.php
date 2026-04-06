<?php
/**
 * Страница настроек модуля prospektweb.propmodificator
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

define('STOP_STATISTICS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'prospektweb.propmodificator';

Loader::includeModule($moduleId);
Loc::loadMessages(__FILE__);

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Нет доступа');
}

$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$tabControl = new CAdminTabControl('tabControl', [[
    'DIV'   => 'edit1',
    'TAB'   => Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_TAB_MAIN'),
    'TITLE' => Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_TAB_MAIN_TITLE'),
]]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['save'])) {
    $fields = [
        'PRODUCTS_IBLOCK_ID',
        'CUSTOM_CONFIG_PROP_CODE',
        'CATALOG_PATH_FILTER',
    ];

    foreach ($fields as $key) {
        COption::SetOptionString($moduleId, $key, trim($_POST[$key] ?? ''));
    }

    COption::SetOptionString($moduleId, 'DEBUG', isset($_POST['DEBUG']) ? 'Y' : 'N');
    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($moduleId) . '&saved=Y');
}

require __DIR__ . '/default_option.php';

$options = [];
foreach ($prospektweb_propmodificator_default_option as $key => $default) {
    $options[$key] = COption::GetOptionString($moduleId, $key, $default);
}
?>

<?php if (($_GET['saved'] ?? '') === 'Y'): ?>
    <div class="adm-info-message-wrap success">
        <div class="adm-info-message"><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_SAVED') ?></div>
    </div>
<?php endif; ?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td class="adm-detail-content-cell-l" width="40%">
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_PRODUCTS_IBLOCK_ID') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_PRODUCTS_IBLOCK_ID_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="PRODUCTS_IBLOCK_ID"
                   value="<?= htmlspecialchars($options['PRODUCTS_IBLOCK_ID']) ?>"
                   size="10" maxlength="10">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_CUSTOM_CONFIG_PROP_CODE') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_CUSTOM_CONFIG_PROP_CODE_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="CUSTOM_CONFIG_PROP_CODE"
                   value="<?= htmlspecialchars($options['CUSTOM_CONFIG_PROP_CODE']) ?>"
                   size="30" maxlength="100">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_CATALOG_PATH_FILTER') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_CATALOG_PATH_FILTER_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="CATALOG_PATH_FILTER"
                   value="<?= htmlspecialchars($options['CATALOG_PATH_FILTER']) ?>"
                   size="40" maxlength="255">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_DEBUG') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_DEBUG_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="checkbox" name="DEBUG" value="Y"<?= ($options['DEBUG'] === 'Y' ? ' checked' : '') ?>>
        </td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="<?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_SAVE') ?>" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
