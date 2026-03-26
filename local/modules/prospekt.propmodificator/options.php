<?php
/**
 * Страница настроек модуля prospekt.propmodificator
 * Путь в панели администратора: /bitrix/admin/settings.php?mid=prospekt.propmodificator
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

define('STOP_STATISTICS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'prospekt.propmodificator';

Loader::includeModule($moduleId);
Loc::loadMessages(__FILE__);

/** @global CUser $USER */
/** @global CMain $APPLICATION */
global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Нет доступа');
}

$APPLICATION->SetTitle('Настройки модуля «Модификатор свойств ТП»');

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$tabControl = new CAdminTabControl('tabControl', [
    [
        'DIV'   => 'edit1',
        'TAB'   => 'Основные настройки',
        'TITLE' => 'Параметры инфоблоков и свойств',
    ],
]);

// ─── Сохранение формы ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && check_bitrix_sessid()
    && isset($_POST['save'])
) {
    $fields = [
        'OFFERS_IBLOCK_ID',
        'PRODUCTS_IBLOCK_ID',
        'FORMAT_PROP_CODE',
        'VOLUME_PROP_CODE',
        'SET_FORMAT_PROP_CODE',
        'SET_VOLUME_PROP_CODE',
        'PRICE_TYPE_ID',
    ];

    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        COption::SetOptionString($moduleId, $key, $val);
    }

    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($moduleId) . '&saved=Y');
}

// ─── Чтение текущих значений ─────────────────────────────────────────────────
require __DIR__ . '/default_option.php';

$options = [];
foreach ($arDefaultOptions as $key => $default) {
    $options[$key] = COption::GetOptionString($moduleId, $key, $default);
}
?>

<?php if ($_GET['saved'] === 'Y'): ?>
    <div class="adm-info-message-wrap success">
        <div class="adm-info-message">Настройки сохранены.</div>
    </div>
<?php endif; ?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <b>ID инфоблока торговых предложений</b><br>
            <small>Инфоблок, где хранятся ТП (по умолчанию 15)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="OFFERS_IBLOCK_ID"
                   value="<?= htmlspecialchars($options['OFFERS_IBLOCK_ID']) ?>"
                   size="10" maxlength="10">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b>ID инфоблока товаров</b><br>
            <small>Инфоблок родительских товаров (по умолчанию 14)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="PRODUCTS_IBLOCK_ID"
                   value="<?= htmlspecialchars($options['PRODUCTS_IBLOCK_ID']) ?>"
                   size="10" maxlength="10">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b>Код свойства ФОРМАТ в ТП</b><br>
            <small>Символьный код свойства с форматами (по умолчанию CALC_PROP_FORMAT)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="FORMAT_PROP_CODE"
                   value="<?= htmlspecialchars($options['FORMAT_PROP_CODE']) ?>"
                   size="30" maxlength="100">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b>Код свойства ТИРАЖ в ТП</b><br>
            <small>Символьный код свойства с тиражами (по умолчанию CALC_PROP_VOLUME)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="VOLUME_PROP_CODE"
                   value="<?= htmlspecialchars($options['VOLUME_PROP_CODE']) ?>"
                   size="30" maxlength="100">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b>Код свойства НАСТРОЙКИ ФОРМАТА в товаре</b><br>
            <small>Multiple-string свойство товара (по умолчанию SET_FORMAT)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="SET_FORMAT_PROP_CODE"
                   value="<?= htmlspecialchars($options['SET_FORMAT_PROP_CODE']) ?>"
                   size="30" maxlength="100">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b>Код свойства НАСТРОЙКИ ТИРАЖА в товаре</b><br>
            <small>Multiple-string свойство товара (по умолчанию SET_VOLUME)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="SET_VOLUME_PROP_CODE"
                   value="<?= htmlspecialchars($options['SET_VOLUME_PROP_CODE']) ?>"
                   size="30" maxlength="100">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b>ID типа цены для интерполяции</b><br>
            <small>Тип цены из справочника (по умолчанию 1 — базовая)</small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="PRICE_TYPE_ID"
                   value="<?= htmlspecialchars($options['PRICE_TYPE_ID']) ?>"
                   size="10" maxlength="10">
        </td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
