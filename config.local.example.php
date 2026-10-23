<?php
/**
 * Example environment config — safe to commit.
 *
 * Setup:
 *   1. Copy this file to config.local.php in the project root
 *   2. Fill in SQL Server + service account details
 *   3. Never commit config.local.php (it is in .gitignore)
 *   4. Run sql/alter_branch_user_login_password.sql once (widen login_password)
 *
 * App users sign in with branch_user.user_id + branch_user.login_password.
 * The SQL connection always uses db_username / db_password below (service account).
 */
return [
    // ── SQL Server (service account) ────────────────────────────────────────
    // Cloud example:
    'db_server'   => 'den1.mssql8.gear.host',
    'db_database' => 'infos',

    // Local example (comment cloud lines above and use these instead):
    // 'db_server'   => 'DESKTOP-GP23B1O\\MSSQL2024',
    // 'db_database' => 'van',

    'db_trust_server_certificate' => true,

    // Required — SQL Server login used by the PHP app (not the ERP user password)
    'db_username' => 'infos',
    'db_password' => '',

    // ── App login ───────────────────────────────────────────────────────────
    // branch_user.user_type values allowed to sign in (A=Admin, U=User)
    'allowed_user_types' => ['A', 'U'],

    // On successful login, upgrade plain-text login_password to password_hash()
    'rehash_login_password_on_login' => true,

    // ── App ─────────────────────────────────────────────────────────────────
    'erp_web_root' => '/',
    'timezone'     => 'Asia/Dubai',
];
