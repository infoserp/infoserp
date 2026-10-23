<?php
/**
 * Example environment config — safe to commit.
 *
 * Setup:
 *   1. Copy this file to config.local.php in the project root
 *   2. Fill in cloud / local SQL Server details
 *   3. Never commit config.local.php (it is in .gitignore)
 *
 * db.php loads config.local.php for server + database.
 * Login still uses the SQL username/password from the login form (session),
 * unless you set db_username / db_password below for a fixed service account.
 */
return [
    // ── SQL Server ──────────────────────────────────────────────────────────
    // Cloud example:
    'db_server'   => 'infoserp.dyndns.org',
    'db_database' => 'infos',

    // Local example (comment cloud lines above and use these instead):
    // 'db_server'   => 'DESKTOP-GP23B1O\\MSSQL2024',
    // 'db_database' => 'van',

    // Required for most self-signed / private cloud SQL TLS setups
    'db_trust_server_certificate' => true,

    // Optional fixed DB login (leave empty to use $_SESSION from login.php)
    'db_username' => '',
    'db_password' => '',

    // ── App ─────────────────────────────────────────────────────────────────
    // Web root for static assets ('/' when site is at http://host:8050/)
    'erp_web_root' => '/',
    'timezone'     => 'Asia/Dubai',
];
