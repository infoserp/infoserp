<?php
// Material Request now lives in modules/material_request/material_request.php
// (header/detail fragments loaded through document_setup). Kept so old links still work.
require_once __DIR__ . '/includes/erp_paths.php';

$qs = $_SERVER['QUERY_STRING'] ?? '';
erp_redirect_url(erp_url('modules/material_request/material_request') . ($qs !== '' ? '?' . $qs : ''));
