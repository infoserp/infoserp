<?php
/**
 * App-user authentication helpers (branch_user.login_password).
 * DB connection uses the service account from config.local.php — not the form password.
 */

if (!function_exists('erp_password_is_hashed')) {
    /**
     * Detect bcrypt / argon2 hashes produced by password_hash().
     * Also matches $2y$ / $2a$ / $2b$ by prefix (SSMS paste can confuse some PHP builds).
     */
    function erp_password_is_hashed($stored)
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return false;
        }
        if (preg_match('/^\$2[ayb]\$\d{2}\$/', $stored) === 1 && strlen($stored) >= 59) {
            return true;
        }
        if (function_exists('password_get_info')) {
            $info = password_get_info($stored);
            return !empty($info['algo']);
        }
        return false;
    }
}

if (!function_exists('erp_password_verify')) {
    /**
     * Verify a password against branch_user.login_password.
     * Supports hashed values and legacy plain text (exact or uppercased legacy).
     *
     * @return bool
     */
    function erp_password_verify($plain, $stored)
    {
        $plain  = (string) $plain;
        $stored = trim((string) $stored);
        // Strip UTF-8 BOM / stray NULs that SSMS / copy-paste sometimes leave in the column
        $stored = preg_replace('/^\xEF\xBB\xBF/', '', $stored);
        $stored = str_replace("\0", '', $stored);
        if ($stored === '') {
            return false;
        }
        if (erp_password_is_hashed($stored)) {
            return password_verify($plain, $stored);
        }
        // Legacy plain-text passwords (desktop ERP often stored UPPERCASE)
        return hash_equals($stored, $plain)
            || hash_equals($stored, strtoupper($plain));
    }
}

if (!function_exists('erp_password_hash')) {
    function erp_password_hash($plain)
    {
        return password_hash((string) $plain, PASSWORD_DEFAULT);
    }
}

if (!function_exists('erp_password_needs_rehash')) {
    /**
     * True when stored value is plain text, or an outdated hash algorithm/options.
     */
    function erp_password_needs_rehash($stored)
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return false;
        }
        if (!erp_password_is_hashed($stored)) {
            return true;
        }
        return password_needs_rehash($stored, PASSWORD_DEFAULT);
    }
}

if (!function_exists('erp_auth_upgrade_login_password')) {
    /**
     * Store a hashed login_password for every branch_user row with this user_id.
     * Silently skips if the column is still too short for a bcrypt hash.
     *
     * @return bool True if at least one row was updated
     */
    function erp_auth_upgrade_login_password(PDO $con, $userId, $plainPassword)
    {
        $hash = erp_password_hash($plainPassword);
        if (strlen($hash) < 50) {
            return false;
        }
        try {
            $stmt = $con->prepare(
                "UPDATE branch_user
                 SET login_password = ?, last_userid = ?, last_userdate = GETDATE()
                 WHERE user_id = ?"
            );
            $stmt->execute([$hash, $userId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Typically "String or binary data would be truncated" if column is still varchar(15)
            error_log('erp_auth_upgrade_login_password: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('erp_auth_allowed_user_types')) {
    /**
     * @return string[] Uppercase codes, e.g. ['A','U']
     */
    function erp_auth_allowed_user_types(array $erpConfig = [])
    {
        $raw = $erpConfig['allowed_user_types'] ?? ['A', 'U'];
        if (!is_array($raw)) {
            $raw = preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        $out = [];
        foreach ($raw as $t) {
            $t = strtoupper(trim((string) $t));
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out ?: ['A', 'U'];
    }
}

if (!function_exists('erp_auth_find_user')) {
    /**
     * Load one branch_user identity for login (any branch row for this user_id).
     * Temporary: uses edit_password (plain text) for sign-in.
     */
    function erp_auth_find_user(PDO $con, $userId)
    {
        $stmt = $con->prepare(
            "SELECT TOP 1
                    bu.user_id,
                    bu.user_type,
                    bu.edit_password,
                    bu.user_name,
                    bu.allow_allbranch
             FROM   branch_user bu
             WHERE  bu.user_id = ?
             ORDER BY CASE WHEN ISNULL(bu.edit_password, '') <> '' THEN 0 ELSE 1 END,
                      bu.branch_code"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
