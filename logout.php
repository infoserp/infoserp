<?php

require_once __DIR__ . '/includes/erp_session.php';
erp_session_configure_ini();
session_start();
require_once __DIR__ . '/includes/erp_paths.php';

$_SESSION = [];
if (session_destroy()) {
    erp_redirect('login');
}
erp_redirect('login');
?>
