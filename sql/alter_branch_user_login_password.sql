-- Widen branch_user.login_password so PHP password_hash() values fit (bcrypt ~60 chars).
-- Run once on each database (local + GearHost cloud) before enabling hashed logins.
-- Safe to re-run: same definition.

IF COL_LENGTH('dbo.branch_user', 'login_password') IS NOT NULL
BEGIN
    ALTER TABLE dbo.branch_user ALTER COLUMN login_password varchar(255) NULL;
END
GO
