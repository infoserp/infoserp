<?php
/**
 * Session lifetime helpers (PHP session auth — not Sanctum).
 * Idle timeout default 60 minutes; absolute max default 12 hours.
 */

if (!function_exists('erp_session_limits')) {
    /**
     * @return array{idle:int,max:int}
     */
    function erp_session_limits()
    {
        static $limits = null;
        if ($limits !== null) {
            return $limits;
        }
        $idle = 3600;   // 60 minutes
        $max  = 43200;  // 12 hours
        if (function_exists('erp_load_config')) {
            $cfg = erp_load_config();
            if (isset($cfg['session_idle_seconds'])) {
                $idle = max(60, (int) $cfg['session_idle_seconds']);
            }
            if (isset($cfg['session_max_seconds'])) {
                $max = max($idle, (int) $cfg['session_max_seconds']);
            }
        }
        $limits = ['idle' => $idle, 'max' => $max];
        return $limits;
    }
}

if (!function_exists('erp_session_configure_ini')) {
    /** Call before session_start() when possible. */
    function erp_session_configure_ini()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!function_exists('erp_load_config')) {
            $cfgPath = dirname(__DIR__) . '/includes/erp_config.php';
            if (is_file($cfgPath)) {
                require_once $cfgPath;
            }
        }
        $lim = erp_session_limits();
        $gc = max($lim['idle'], $lim['max']);
        @ini_set('session.gc_maxlifetime', (string) $gc);
        @ini_set('session.cookie_lifetime', (string) $gc);
    }
}

if (!function_exists('erp_session_mark_login')) {
    function erp_session_mark_login()
    {
        $now = time();
        $_SESSION['login_at'] = $now;
        $_SESSION['last_activity'] = $now;
    }
}

if (!function_exists('erp_session_is_ajax')) {
    function erp_session_is_ajax()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
        if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
            return true;
        }
        if (defined('DO_SEND_JSON_API') && DO_SEND_JSON_API) {
            return true;
        }
        return false;
    }
}

if (!function_exists('erp_session_expire_to_login')) {
    /** Destroy session and go to login with no expiry message. */
    function erp_session_expire_to_login()
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
        require_once __DIR__ . '/erp_paths.php';
        $loginUrl = erp_url('login');
        if (erp_session_is_ajax()) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'redirect' => $loginUrl], JSON_UNESCAPED_UNICODE);
            exit;
        }
        erp_redirect('login');
    }
}

if (!function_exists('erp_session_enforce')) {
    /**
     * Enforce idle + max lifetime for an authenticated session.
     * Call after confirming $_SESSION['username'] is set.
     */
    function erp_session_enforce()
    {
        $lim = erp_session_limits();
        $now = time();

        if (!isset($_SESSION['login_at'])) {
            $_SESSION['login_at'] = $now;
        }
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = $now;
        }

        $idleAge = $now - (int) $_SESSION['last_activity'];
        $lifeAge = $now - (int) $_SESSION['login_at'];

        if (($lim['idle'] > 0 && $idleAge > $lim['idle'])
            || ($lim['max'] > 0 && $lifeAge > $lim['max'])) {
            erp_session_expire_to_login();
        }

        $_SESSION['last_activity'] = $now;
    }
}
