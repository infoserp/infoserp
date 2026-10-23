<?php
require dirname(__DIR__) . '/db.php';
include dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/includes/approval_count_queries.php';

header('Content-Type: application/json; charset=utf-8');

$cache_ttl      = 60;
$force_refresh  = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$cache_scope    = ($_SESSION['company_code'] ?? '') . '|' . ($_SESSION['branch_code'] ?? '') . '|' . ($_SESSION['username'] ?? '');
$cache_data_key = 'dashboard_counts_cache';
$cache_time_key = 'dashboard_counts_cache_time';
$cache_scope_key = 'dashboard_counts_cache_scope';

if (!isset($_SESSION[$cache_data_key]) || !is_array($_SESSION[$cache_data_key])) {
    $_SESSION[$cache_data_key] = [];
    $_SESSION[$cache_time_key] = [];
}

$cached = false;
if (
    !$force_refresh
    && isset($_SESSION[$cache_scope_key][$cache_scope])
    && $_SESSION[$cache_scope_key][$cache_scope] === true
    && isset($_SESSION[$cache_time_key][$cache_scope])
    && (time() - (int) $_SESSION[$cache_time_key][$cache_scope]) < $cache_ttl
    && isset($_SESSION[$cache_data_key][$cache_scope])
) {
    $payload = $_SESSION[$cache_data_key][$cache_scope];
    $cached  = true;
} else {
    try {
        $payload = erp_fetch_dashboard_counts($con, $_SESSION);
        $payload['success'] = true;
        $payload['cached']  = false;

        $_SESSION[$cache_data_key][$cache_scope]  = $payload;
        $_SESSION[$cache_time_key][$cache_scope]  = time();
        $_SESSION[$cache_scope_key][$cache_scope] = true;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Could not load counts',
            'by_key'  => [],
        ]);
        exit;
    }
}

$payload['success'] = true;
$payload['cached']  = $cached;

echo json_encode($payload);
