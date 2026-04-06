<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

header('Content-Type: application/json; charset=utf-8');

if (!Loader::includeModule('prospektweb.propmodificator')) {
    echo json_encode(['success' => false, 'error' => 'Module not loaded'], JSON_UNESCAPED_UNICODE);
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

if ($action === 'meta') {
    echo json_encode(['success' => true, 'properties' => []], JSON_UNESCAPED_UNICODE);
    die();
}

if ($action === 'create_marker') {
    echo json_encode(['success' => false, 'error' => 'Marker API removed in UI-only mode'], JSON_UNESCAPED_UNICODE);
    die();
}

echo json_encode(['success' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
die();
