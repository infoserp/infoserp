<?php
/**
 * Application root path helpers — correct asset URLs from any page / public URL.
 */

if (!function_exists('erp_base_path')) {
    function erp_base_path() {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        if (defined('ERP_WEB_ROOT')) {
            $base = ERP_WEB_ROOT;
        } else {
            $appRoot = realpath(dirname(__DIR__));
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

            if ($appRoot && $docRoot && strncmp($appRoot, $docRoot, strlen($docRoot)) === 0) {
                $rel = trim(str_replace('\\', '/', substr($appRoot, strlen($docRoot))), '/');
                $base = $rel === '' ? '/' : '/' . $rel . '/';
            } else {
                $base = '/';
            }
        }

        if ($base === '' || $base === '/') {
            return '/';
        }
        if ($base[0] !== '/') {
            $base = '/' . $base;
        }
        if (substr($base, -1) !== '/') {
            $base .= '/';
        }
        return $base;
    }
}

if (!function_exists('erp_asset')) {
    function erp_asset($relativePath) {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');
        $base = erp_base_path();
        return $base === '/' ? '/' . $path : $base . $path;
    }
}

if (!function_exists('erp_asset_v')) {
    /** erp_asset() plus ?v=<file mtime> so browsers refetch the file after it changes. */
    function erp_asset_v($relativePath) {
        $file = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $ver  = is_file($file) ? filemtime($file) : '';
        return erp_asset($relativePath) . ($ver !== '' ? '?v=' . $ver : '');
    }
}

if (!function_exists('erp_font_awesome_href')) {
    /**
     * Local Font Awesome 4 if vendor/ is deployed; otherwise CDN so icons never 404.
     */
    function erp_font_awesome_href() {
        $rel = 'vendor/font-awesome/css/font-awesome.min.css';
        $abs = dirname(__DIR__) . '/' . $rel;
        if (is_file($abs)) {
            return erp_asset_v($rel);
        }
        return 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css';
    }
}

if (!function_exists('erp_url')) {
    function erp_url($route) {
        $route = str_replace('\\', '/', (string) $route);
        $route = ltrim($route, '/');

        $query = '';
        $qpos = strpos($route, '?');
        if ($qpos !== false) {
            $query = substr($route, $qpos);
            $route = substr($route, 0, $qpos);
        }

        if (substr($route, -4) === '.php') {
            $route = substr($route, 0, -4);
        }

        $base = erp_base_path();
        $clean = ($base === '/' ? '/' : $base) . $route;
        if ($route === '') {
            $clean = ($base === '/' ? '/' : rtrim($base, '/'));
        }
        return $clean . $query;
    }
}

if (!function_exists('erp_is_embedded')) {
    function erp_is_embedded() {
        return isset($_GET['embed']) && (string) $_GET['embed'] === '1';
    }
}

if (!function_exists('erp_iframe_url')) {
    function erp_iframe_url($route) {
        $url = erp_url($route);
        return erp_embed_href($url);
    }
}

if (!function_exists('erp_embed_href')) {
    function erp_embed_href($href) {
        $href = (string) $href;
        if ($href === '' || strpos($href, 'embed=1') !== false) {
            return $href;
        }
        return $href . (strpos($href, '?') !== false ? '&' : '?') . 'embed=1';
    }
}

if (!function_exists('erp_shell_url')) {
    function erp_shell_url() {
        return erp_url('home');
    }
}

if (!function_exists('erp_request_in_iframe')) {
    function erp_request_in_iframe() {
        return isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe';
    }
}

if (!function_exists('erp_redirect')) {
    function erp_redirect($route, $status = 302) {
        header('Location: ' . erp_url($route), true, (int) $status);
        exit;
    }
}

if (!function_exists('erp_redirect_url')) {
    function erp_redirect_url($url, $status = 302) {
        header('Location: ' . $url, true, (int) $status);
        exit;
    }
}

if (!function_exists('erp_guard_standalone_get')) {
    /**
     * App pages load inside the /home iframe shell. Direct GET requests redirect to home.
     * Never load the /home shell inside an iframe (causes duplicate sidebars).
     */
    function erp_guard_standalone_get() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        if (erp_is_embedded()) {
            return;
        }
        if (erp_request_in_iframe()) {
            erp_redirect_url(erp_iframe_url('dashboard'));
        }
        erp_redirect('home');
    }
}

if (!function_exists('erp_guard_shell_not_in_iframe')) {
    function erp_guard_shell_not_in_iframe() {
        if (erp_request_in_iframe() || erp_is_embedded()) {
            erp_redirect_url(erp_iframe_url('dashboard'));
        }
    }
}

if (!function_exists('erp_page_url')) {
    function erp_page_url($route) {
        return erp_is_embedded() ? erp_iframe_url($route) : erp_url($route);
    }
}

if (!function_exists('erp_approval_list_url')) {
    function erp_approval_list_url($doc_type) {
        return erp_iframe_url('approvalline?doc_type=' . rawurlencode((string) $doc_type));
    }
}

if (!function_exists('erp_approval_view_url')) {
    function erp_approval_view_url($doc_type, $doc_id) {
        return erp_iframe_url(
            'viewdetailed?doc_type=' . rawurlencode((string) $doc_type)
            . '&doc_id=' . rawurlencode((string) $doc_id)
        );
    }
}

if (!function_exists('erp_approval_approve_url')) {
    function erp_approval_approve_url($doc_type, $doc_id) {
        return erp_iframe_url(
            'approve?doc_type=' . rawurlencode((string) $doc_type)
            . '&doc_id=' . rawurlencode((string) $doc_id)
        );
    }
}

if (!function_exists('erp_po_attachments_url')) {
    function erp_po_attachments_url($doc_no) {
        return erp_iframe_url('po_attachments?doc_no=' . rawurlencode((string) $doc_no));
    }
}
