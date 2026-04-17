<?php

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\AjaxController;
use Prospektweb\PropModificator\Infrastructure\Http\RequestThrottler;
use Prospektweb\PropModificator\Infrastructure\Http\ServerContext;
use Prospektweb\PropModificator\Infrastructure\Http\SessionStorage;

$server = ServerContext::fromGlobals();
require_once $server->documentRoot() . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$buildError = static function (string $message, string $errorCode, array $details = []): array {
    $response = ['success' => false, 'errorCode' => $errorCode, 'error' => $message];
    if ($details !== []) {
        $response['errorDetails'] = $details;
    }

    return $response;
};

if ($server->requestMethod() !== 'POST') {
    echo json_encode($buildError('POST required', 'METHOD_NOT_ALLOWED'), JSON_UNESCAPED_UNICODE);
    die();
}

if (!check_bitrix_sessid()) {
    echo json_encode($buildError('CSRF check failed', 'INVALID_SESSID'), JSON_UNESCAPED_UNICODE);
    die();
}

$sessionId = session_id();
$clientIp = (string)($server->get('REMOTE_ADDR') ?? '');
$throttler = new RequestThrottler(SessionStorage::fromGlobals(), 300);
$throttleCheck = $throttler->allow($clientIp, $sessionId);
if (!$throttleCheck['ok']) {
    header('Retry-After: ' . max(1, (int)ceil($throttleCheck['retryAfterMs'] / 1000)));
    echo json_encode(
        $buildError('Too many requests', 'THROTTLED', ['retryAfterMs' => $throttleCheck['retryAfterMs']]),
        JSON_UNESCAPED_UNICODE
    );
    die();
}

if (!Loader::includeModule('prospektweb.propmodificator')) {
    echo json_encode($buildError('Module not loaded', 'MODULE_NOT_LOADED'), JSON_UNESCAPED_UNICODE);
    die();
}

try {
    echo json_encode(
        AjaxController::calcPrice(),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(
        $buildError('Internal server error', 'INTERNAL_ERROR', ['message' => $e->getMessage()]),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
    );
}
die();
