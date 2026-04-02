<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Config;

header('Content-Type: application/json; charset=utf-8');

if (!Loader::includeModule('prospektweb.propmodificator') || !Loader::includeModule('iblock')) {
    echo json_encode(['success' => false, 'error' => 'Modules not loaded'], JSON_UNESCAPED_UNICODE);
    die();
}

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    die();
}

if (!check_bitrix_sessid()) {
    echo json_encode(['success' => false, 'error' => 'Invalid sessid'], JSON_UNESCAPED_UNICODE);
    die();
}

$action = (string)($_REQUEST['action'] ?? '');
$offersIblockId = Config::getOffersIblockId();

if ($action === 'meta') {
    $props = [];
    $rsProps = CIBlockProperty::GetList(['SORT' => 'ASC'], [
        'IBLOCK_ID' => $offersIblockId,
        'ACTIVE' => 'Y',
        'PROPERTY_TYPE' => 'L',
    ]);

    while ($arProp = $rsProps->Fetch()) {
        $props[] = [
            'id' => (int)$arProp['ID'],
            'code' => (string)$arProp['CODE'],
            'name' => (string)$arProp['NAME'],
        ];
    }

    echo json_encode(['success' => true, 'properties' => $props], JSON_UNESCAPED_UNICODE);
    die();
}

if ($action === 'create_marker') {
    $propertyId = (int)($_POST['property_id'] ?? 0);
    $xmlId = trim((string)($_POST['xml_id'] ?? ''));
    $value = trim((string)($_POST['value'] ?? ''));

    if ($propertyId <= 0 || $xmlId === '' || $value === '') {
        echo json_encode(['success' => false, 'error' => 'property_id, xml_id, value required'], JSON_UNESCAPED_UNICODE);
        die();
    }

    $prop = CIBlockProperty::GetList([], [
        'IBLOCK_ID' => $offersIblockId,
        'ID' => $propertyId,
        'ACTIVE' => 'Y',
        'PROPERTY_TYPE' => 'L',
    ])->Fetch();

    if (!$prop) {
        echo json_encode(['success' => false, 'error' => 'Property not found or not list type'], JSON_UNESCAPED_UNICODE);
        die();
    }

    $existing = CIBlockPropertyEnum::GetList([], [
        'PROPERTY_ID' => $propertyId,
        'XML_ID' => $xmlId,
    ])->Fetch();

    $enum = new CIBlockPropertyEnum();

    if ($existing) {
        $enumId = (int)$existing['ID'];
        $updated = $enum->Update($enumId, [
            'PROPERTY_ID' => $propertyId,
            'VALUE' => $value,
            'XML_ID' => $xmlId,
            'SORT' => 9999,
            'DEF' => 'N',
        ]);

        if (!$updated) {
            echo json_encode(['success' => false, 'error' => (string)$enum->LAST_ERROR], JSON_UNESCAPED_UNICODE);
            die();
        }

        echo json_encode(['success' => true, 'enum_id' => $enumId, 'updated' => true], JSON_UNESCAPED_UNICODE);
        die();
    }

    $enumId = (int)$enum->Add([
        'PROPERTY_ID' => $propertyId,
        'VALUE' => $value,
        'XML_ID' => $xmlId,
        'SORT' => 9999,
        'DEF' => 'N',
    ]);

    if ($enumId <= 0) {
        echo json_encode(['success' => false, 'error' => (string)$enum->LAST_ERROR], JSON_UNESCAPED_UNICODE);
        die();
    }

    echo json_encode(['success' => true, 'enum_id' => $enumId, 'updated' => false], JSON_UNESCAPED_UNICODE);
    die();
}

echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
die();
