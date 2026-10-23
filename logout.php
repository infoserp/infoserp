<?php

session_start();
require_once __DIR__ . '/includes/erp_paths.php';

if (session_destroy()) {
    erp_redirect('');
}

?>
