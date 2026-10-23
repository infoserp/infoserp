<?php

if (!isset($_SESSION["username"])) {
    require_once __DIR__ . '/includes/erp_paths.php';
    erp_redirect('login');
}

?>
