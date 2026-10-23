<?php
/**
 * Load DO mail config from outside wwwroot (secrets not web-accessible).
 */

if (!function_exists('erp_do_mail_config_path')) {
    function erp_do_mail_config_path()
    {
        $envPath = getenv('DO_MAIL_CONFIG_PATH');
        if ($envPath !== false && $envPath !== '') {
            return $envPath;
        }

        if (defined('DO_MAIL_CONFIG_PATH') && DO_MAIL_CONFIG_PATH !== '') {
            return DO_MAIL_CONFIG_PATH;
        }

        $inetpub = realpath(dirname(__DIR__, 3));
        if ($inetpub !== false) {
            return $inetpub . DIRECTORY_SEPARATOR . 'Approval_config' . DIRECTORY_SEPARATOR . 'do_mail_config.php';
        }

        return 'C:\\inetpub\\Approval_config\\do_mail_config.php';
    }
}

if (!function_exists('erp_load_do_mail_config')) {
    function erp_load_do_mail_config()
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $path = erp_do_mail_config_path();
        if (!is_readable($path)) {
            throw new RuntimeException(
                'Mail config not found or not readable: ' . $path
                . '. Create this file outside wwwroot (see includes/do_mail_config.example.php).'
            );
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Mail config must return an array: ' . $path);
        }

        $config = $loaded;
        return $config;
    }
}
