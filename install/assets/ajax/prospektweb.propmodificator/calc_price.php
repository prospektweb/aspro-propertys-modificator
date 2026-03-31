<?php
/**
 * AJAX-роутер: пересчёт цены товара для произвольного тиража/формата.
 *
 * Устанавливается установщиком модуля в /ajax/prospektweb.propmodificator/calc_price.php.
 *
 * Принимает POST-параметры:
 *   productId   — ID товара (родитель)
 *   volume      — тираж (int, опционально)
 *   width       — ширина мм (int, опционально)
 *   height      — высота мм (int, опционально)
 *   other_props — [propId => enumId] (опционально)
 *   sessid      — CSRF-токен Bitrix
 *
 * Возвращает JSON (см. AjaxController::calcPrice()).
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Только POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    die();
}

// ── Проверяем CSRF-токен ──────────────────────────────────────────────────────

if (!check_bitrix_sessid()) {
    echo json_encode(['success' => false, 'error' => 'CSRF check failed'], JSON_UNESCAPED_UNICODE);
    die();
}

// ── Подключаем модуль и вызываем контроллер ───────────────────────────────────

use Bitrix\Main\Loader;
use Prospektweb\PropModificator\AjaxController;

if (!Loader::includeModule('prospektweb.propmodificator')) {
    echo json_encode(['success' => false, 'error' => 'Module not loaded'], JSON_UNESCAPED_UNICODE);
    die();
}

echo json_encode(AjaxController::calcPrice(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
die();
