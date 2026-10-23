<?php
/**
 * Canonical app shell — fixed browser URL (/home).
 * Sidebar stays here; module pages load in the iframe with ?embed=1.
 */
define('ERP_IS_SHELL', true);
require __DIR__ . '/approvalmenu.php';
