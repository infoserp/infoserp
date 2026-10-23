<?php
/**
 * Legacy endpoint — forwards POST requests to the handler in do_sign.php
 */
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . DIRECTORY_SEPARATOR . 'do_sign.php';
require __DIR__ . '/do_sign.php';
