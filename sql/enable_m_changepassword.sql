-- Enable Admin > Change Password (menu_code = m_changepassword) in user_menu.
-- Parent is menu_name 'Admin' (same pattern as m_documentsetup).
-- Run on each database (local + cloud) as needed.

-- 1) Turn on for users who already have the menu row
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_changepassword'
  AND  ISNULL(active, 'N') <> 'Y';

-- 2) Insert for users who have Document Setup but are missing Change Password
INSERT INTO dbo.user_menu (
    company_code, branch_code, user_id,
    menu_code, menu_name, menu_name_arabic, icon_name,
    parent_code, sort_order, menu_level, active, icon
)
SELECT
    ds.company_code,
    ds.branch_code,
    ds.user_id,
    'm_changepassword',
    'Change Password',
    N'',
    N'',
    'Admin',
    '366',
    NULL,
    'Y',
    'N'
FROM dbo.user_menu ds
WHERE ds.menu_code = 'm_documentsetup'
  AND NOT EXISTS (
        SELECT 1
        FROM   dbo.user_menu x
        WHERE  x.company_code = ds.company_code
          AND  x.branch_code  = ds.branch_code
          AND  x.user_id      = ds.user_id
          AND  x.menu_code    = 'm_changepassword'
  );

-- Verify
SELECT company_code, branch_code, user_id, menu_code, menu_name, parent_code, sort_order, active
FROM   dbo.user_menu
WHERE  menu_code = 'm_changepassword'
ORDER BY user_id, company_code, branch_code;
