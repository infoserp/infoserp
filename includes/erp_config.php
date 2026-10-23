<?php
/**
 * Load config.local.php once (safe to call from db.php and login.php).
 * Plain require() on a returning file returns true the 2nd time — that breaks config.
 */

if (!function_exists('erp_load_config')) {
    function erp_load_config()
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';
        if (!is_readable($file)) {
            $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.example.php';
        }
        if (!is_readable($file)) {
            $config = [];
            return $config;
        }

        $loaded = require $file;
        $config = is_array($loaded) ? $loaded : [];
        return $config;
    }
}
