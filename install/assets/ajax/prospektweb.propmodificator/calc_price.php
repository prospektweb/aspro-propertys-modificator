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

$emitJson = static function (array $payload): void {
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ($json === false) {
        $json = '{"success":false,"errorCode":"JSON_ENCODE_FAILED","error":"Failed to encode response"}';
    }

    echo $json;
};

$buildError = static function (string $message, string $errorCode, array $details = []): array {
    $response = ['success' => false, 'errorCode' => $errorCode, 'error' => $message];
    if ($details !== []) {
        $response['errorDetails'] = $details;
    }

    return $response;
};

if ($server->requestMethod() !== 'POST') {
    $emitJson($buildError('POST required', 'METHOD_NOT_ALLOWED'));
    die();
}

if (!check_bitrix_sessid()) {
    $emitJson($buildError('CSRF check failed', 'INVALID_SESSID'));
    die();
}

$sessionId = session_id();
$clientIp = (string)($server->get('REMOTE_ADDR') ?? '');
$throttler = new RequestThrottler(SessionStorage::fromGlobals(), 300);
$throttleCheck = $throttler->allow($clientIp, $sessionId);
if (!$throttleCheck['ok']) {
    header('Retry-After: ' . max(1, (int)ceil($throttleCheck['retryAfterMs'] / 1000)));
    $emitJson($buildError('Too many requests', 'THROTTLED', ['retryAfterMs' => $throttleCheck['retryAfterMs']]));
    die();
}

if (!Loader::includeModule('prospektweb.propmodificator')) {
    $emitJson($buildError('Module not loaded', 'MODULE_NOT_LOADED'));
    die();
}

try {
    $emitJson(AjaxController::calcPrice());
} catch (\Throwable $e) {
    http_response_code(500);
    $emitJson($buildError('Internal server error', 'INTERNAL_ERROR', ['message' => (string)$e->getMessage()]));
}
die();
