<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\AjaxController;
use Prospektweb\PropModificator\Infrastructure\Http\ServerContext;

$server = ServerContext::fromGlobals();
require_once $server->documentRoot() . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($server->requestMethod() !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    die();
}

if (!check_bitrix_sessid()) {
    echo json_encode(['success' => false, 'error' => 'CSRF check failed'], JSON_UNESCAPED_UNICODE);
    die();
}

if (!Loader::includeModule('prospektweb.propmodificator')) {
    echo json_encode(['success' => false, 'error' => 'Module not loaded'], JSON_UNESCAPED_UNICODE);
    die();
}

echo json_encode(AjaxController::calcPrice(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
die();
