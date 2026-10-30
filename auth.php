<?php

require_once __DIR__ . '/includes/erp_paths.php';
require_once __DIR__ . '/includes/erp_config.php';
require_once __DIR__ . '/includes/erp_session.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    erp_session_configure_ini();
    @session_start();
}

if (!isset($_SESSION['username']) || trim((string) $_SESSION['username']) === '') {
    erp_session_expire_to_login();
}

erp_session_enforce();

?>
