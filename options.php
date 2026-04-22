<?php
/**
 * Страница настроек модуля prospektweb.propmodificator
 * Путь в панели администратора: /bitrix/admin/settings.php?mid=prospektweb.propmodificator
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

define('STOP_STATISTICS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$moduleId = 'prospektweb.propmodificator';

Loader::includeModule($moduleId);
Loader::includeModule('iblock');
Loc::loadMessages(__FILE__);

/** @global CUser $USER */
/** @global CMain $APPLICATION */
global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Нет доступа');
}

$APPLICATION->SetTitle(Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$tabControl = new CAdminTabControl('tabControl', [
    [
        'DIV'   => 'edit1',
        'TAB'   => Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_TAB_MAIN'),
        'TITLE' => Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_TAB_MAIN_TITLE'),
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
        'CUSTOM_CONFIG_PROP_CODE',
        'PRICE_TYPE_ID',
        'CATALOG_PATH_FILTER',
        'HIDDEN_OFFER_VALUE_IDS',
    ];

    foreach ($fields as $key) {
        $val = trim($_POST[$key] ?? '');
        COption::SetOptionString($moduleId, $key, $val);
    }

    // Checkbox: absent in POST means unchecked
    COption::SetOptionString($moduleId, 'DEBUG', isset($_POST['DEBUG']) ? 'Y' : 'N');

    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($moduleId) . '&saved=Y');
}

// ─── Чтение текущих значений ─────────────────────────────────────────────────
require __DIR__ . '/default_option.php';

$options = [];
foreach ($prospektweb_propmodificator_default_option as $key => $default) {
    $options[$key] = COption::GetOptionString($moduleId, $key, $default);
}

$calcProperties = [];
$offersIblockId = (int)($options['OFFERS_IBLOCK_ID'] ?? 0);
if ($offersIblockId > 0 && Loader::includeModule('iblock')) {
    $propertyResult = CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $offersIblockId, 'ACTIVE' => 'Y', 'CODE' => 'CALC_%']
    );

    while ($property = $propertyResult->Fetch()) {
        $propertyId = (int)$property['ID'];
        if ($propertyId <= 0) {
            continue;
        }

        $calcProperties[$propertyId] = [
            'id' => $propertyId,
            'code' => (string)$property['CODE'],
            'name' => (string)$property['NAME'],
            'type' => (string)$property['PROPERTY_TYPE'],
            'values' => [],
        ];
    }

    foreach ($calcProperties as $propertyId => $property) {
        if ($property['type'] === 'L') {
            $enumResult = CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC', 'VALUE' => 'ASC'],
                ['PROPERTY_ID' => $propertyId]
            );
            while ($enum = $enumResult->Fetch()) {
                $valueId = (int)$enum['ID'];
                if ($valueId <= 0) {
                    continue;
                }
                $calcProperties[$propertyId]['values'][] = [
                    'id' => $valueId,
                    'name' => (string)$enum['VALUE'],
                ];
            }
        }
    }
}

$hiddenValueIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s;]+/', (string)($options['HIDDEN_OFFER_VALUE_IDS'] ?? '')) ?: []))));
?>

<?php if ($_GET['saved'] === 'Y'): ?>
    <div class="adm-info-message-wrap success">
        <div class="adm-info-message"><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_SAVED') ?></div>
    </div>
<?php endif; ?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($moduleId) ?>">
    <?= bitrix_sessid_post() ?>

    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%" class="adm-detail-content-cell-l">
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_OFFERS_IBLOCK_ID') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_OFFERS_IBLOCK_ID_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="OFFERS_IBLOCK_ID"
                   value="<?= htmlspecialchars($options['OFFERS_IBLOCK_ID']) ?>"
                   size="10" maxlength="10">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
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
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_FORMAT_PROP_CODE') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_FORMAT_PROP_CODE_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="FORMAT_PROP_CODE"
                   value="<?= htmlspecialchars($options['FORMAT_PROP_CODE']) ?>"
                   size="30" maxlength="100">
        </td>
    </tr>

    <tr>
        <td class="adm-detail-content-cell-l">
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_VOLUME_PROP_CODE') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_VOLUME_PROP_CODE_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="VOLUME_PROP_CODE"
                   value="<?= htmlspecialchars($options['VOLUME_PROP_CODE']) ?>"
                   size="30" maxlength="100">
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
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_PRICE_TYPE_ID') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_PRICE_TYPE_ID_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <input type="text" name="PRICE_TYPE_ID"
                   value="<?= htmlspecialchars($options['PRICE_TYPE_ID']) ?>"
                   size="10" maxlength="10">
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
            <b><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS') ?></b><br>
            <small><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS_HINT') ?></small>
        </td>
        <td class="adm-detail-content-cell-r">
            <div id="pmod-hidden-values-settings" style="display:flex; flex-direction:column; gap:10px; max-width:760px;">
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <select id="pmod-calc-prop-select" style="min-width:220px;">
                        <option value=""><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS_PROP_PLACEHOLDER') ?></option>
                    </select>
                    <select id="pmod-calc-value-select" style="min-width:220px;" disabled>
                        <option value=""><?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS_VALUE_PLACEHOLDER') ?></option>
                    </select>
                    <button type="button" class="adm-btn" id="pmod-hidden-value-add">
                        <?= Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS_ADD') ?>
                    </button>
                </div>
                <div id="pmod-hidden-values-list" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
            </div>
            <input type="hidden" name="HIDDEN_OFFER_VALUE_IDS" id="pmod-hidden-values-input" value="<?= htmlspecialchars($options['HIDDEN_OFFER_VALUE_IDS']) ?>">
            <script>
                (function () {
                    var properties = <?= CUtil::PhpToJSObject(array_values($calcProperties), false, true) ?>;
                    var selectedIds = <?= CUtil::PhpToJSObject($hiddenValueIds, false, true) ?>;
                    var propSelect = document.getElementById('pmod-calc-prop-select');
                    var valueSelect = document.getElementById('pmod-calc-value-select');
                    var addButton = document.getElementById('pmod-hidden-value-add');
                    var listEl = document.getElementById('pmod-hidden-values-list');
                    var hiddenInput = document.getElementById('pmod-hidden-values-input');
                    var byId = {};

                    properties.forEach(function (prop) {
                        var opt = document.createElement('option');
                        opt.value = String(prop.id);
                        opt.textContent = (prop.code || '') + ' — ' + (prop.name || '');
                        propSelect.appendChild(opt);
                        (prop.values || []).forEach(function (value) {
                            byId[String(value.id)] = {
                                id: Number(value.id),
                                title: (prop.code || '') + ': ' + (value.name || ''),
                            };
                        });
                    });

                    function syncInput() {
                        hiddenInput.value = selectedIds.join(',');
                    }

                    function renderList() {
                        listEl.innerHTML = '';
                        if (!selectedIds.length) {
                            var empty = document.createElement('span');
                            empty.style.color = '#8f959d';
                            empty.textContent = '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS_EMPTY')) ?>';
                            listEl.appendChild(empty);
                            syncInput();
                            return;
                        }

                        selectedIds.forEach(function (id) {
                            var key = String(id);
                            var chip = document.createElement('span');
                            chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border:1px solid #dce0e5; border-radius:12px; background:#fff;';
                            chip.textContent = (byId[key] ? byId[key].title : ('ID ' + key));

                            var removeBtn = document.createElement('button');
                            removeBtn.type = 'button';
                            removeBtn.className = 'adm-btn';
                            removeBtn.style.cssText = 'padding:0 6px; min-height:20px; line-height:18px;';
                            removeBtn.textContent = '×';
                            removeBtn.addEventListener('click', function () {
                                selectedIds = selectedIds.filter(function (v) { return Number(v) !== Number(id); });
                                renderList();
                            });

                            chip.appendChild(removeBtn);
                            listEl.appendChild(chip);
                        });
                        syncInput();
                    }

                    function renderValuesForProperty(propertyId) {
                        valueSelect.innerHTML = '';
                        var placeholder = document.createElement('option');
                        placeholder.value = '';
                        placeholder.textContent = '<?= CUtil::JSEscape(Loc::getMessage('PROSPEKTWEB_PROPMODIFICATOR_OPTIONS_HIDDEN_OFFER_VALUE_IDS_VALUE_PLACEHOLDER')) ?>';
                        valueSelect.appendChild(placeholder);

                        var property = properties.find(function (item) { return Number(item.id) === Number(propertyId); });
                        if (!property || !property.values || !property.values.length) {
                            valueSelect.disabled = true;
                            return;
                        }

                        property.values.forEach(function (value) {
                            var opt = document.createElement('option');
                            opt.value = String(value.id);
                            opt.textContent = value.name + ' (ID: ' + value.id + ')';
                            valueSelect.appendChild(opt);
                        });
                        valueSelect.disabled = false;
                    }

                    propSelect.addEventListener('change', function () {
                        renderValuesForProperty(propSelect.value);
                    });

                    addButton.addEventListener('click', function () {
                        var valueId = parseInt(valueSelect.value, 10);
                        if (!Number.isFinite(valueId) || valueId <= 0) {
                            return;
                        }
                        if (selectedIds.indexOf(valueId) === -1) {
                            selectedIds.push(valueId);
                            selectedIds.sort(function (a, b) { return a - b; });
                        }
                        renderList();
                    });

                    renderList();
                })();
            </script>
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
