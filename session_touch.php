<?php
/**
 * Lightweight keep-alive for idle session tracking (JSON).
 */
define('DO_SEND_JSON_API', true);
include __DIR__ . '/auth.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
