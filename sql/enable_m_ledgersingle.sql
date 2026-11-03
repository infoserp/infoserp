-- Enable Reports > Financial Report > Ledger Report-Single (menu_code = m_ledgersingle).
-- Parent group: menu_name 'Financial Report' (menu_code m_rp-financial, parent_code 'Report').
-- Root: menu_name 'Reports' (menu_code m_reports). PHP maps parent_code 'Report' → m_reports.
-- Run on each database (local + cloud) as needed.

-- 1) Ensure Reports root is active
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_reports'
  AND  ISNULL(active, 'N') <> 'Y';

-- 2) Ensure Financial Report group is active (parent_code stays 'Report')
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_rp-financial'
  AND  ISNULL(active, 'N') <> 'Y';

-- 3) Turn on Ledger Report-Single for users who already have the menu row
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_ledgersingle'
  AND  ISNULL(active, 'N') <> 'Y';

-- 4) Insert Ledger Report-Single for users who have Sales Invoice but are missing it
INSERT INTO dbo.user_menu (
    company_code, branch_code, user_id,
    menu_code, menu_name, menu_name_arabic, icon_name,
    parent_code, sort_order, menu_level, active, icon
)
SELECT
    si.company_code,
    si.branch_code,
    si.user_id,
    'm_ledgersingle',
    'Ledger Report-Single',
    N'',
    N'',
    'Financial Report',
    '310',
    NULL,
    'Y',
    'N'
FROM dbo.user_menu si
WHERE si.menu_code = 'm_txn_salesinvoice'
  AND NOT EXISTS (
        SELECT 1
        FROM   dbo.user_menu x
        WHERE  x.company_code = si.company_code
          AND  x.branch_code  = si.branch_code
          AND  x.user_id      = si.user_id
          AND  x.menu_code    = 'm_ledgersingle'
  );

-- 5) Ensure those users have an active Financial Report parent row
INSERT INTO dbo.user_menu (
    company_code, branch_code, user_id,
    menu_code, menu_name, menu_name_arabic, icon_name,
    parent_code, sort_order, menu_level, active, icon
)
SELECT
    ls.company_code,
    ls.branch_code,
    ls.user_id,
    'm_rp-financial',
    'Financial Report',
    N'',
    N'',
    'Report',
    '350',
    NULL,
    'Y',
    'N'
FROM dbo.user_menu ls
WHERE ls.menu_code = 'm_ledgersingle'
  AND NOT EXISTS (
        SELECT 1
        FROM   dbo.user_menu x
        WHERE  x.company_code = ls.company_code
          AND  x.branch_code  = ls.branch_code
          AND  x.user_id      = ls.user_id
          AND  x.menu_code    = 'm_rp-financial'
  );

-- 6) Ensure those users have an active Reports root row
INSERT INTO dbo.user_menu (
    company_code, branch_code, user_id,
    menu_code, menu_name, menu_name_arabic, icon_name,
    parent_code, sort_order, menu_level, active, icon
)
SELECT
    fr.company_code,
    fr.branch_code,
    fr.user_id,
    'm_reports',
    'Reports',
    N'',
    N'',
    'Root',
    '003',
    NULL,
    'Y',
    'N'
FROM dbo.user_menu fr
WHERE fr.menu_code = 'm_rp-financial'
  AND NOT EXISTS (
        SELECT 1
        FROM   dbo.user_menu x
        WHERE  x.company_code = fr.company_code
          AND  x.branch_code  = fr.branch_code
          AND  x.user_id      = fr.user_id
          AND  x.menu_code    = 'm_reports'
  );

-- Verify
SELECT company_code, branch_code, user_id, menu_code, menu_name, parent_code, sort_order, active
FROM   dbo.user_menu
WHERE  menu_code IN ('m_reports', 'm_rp-financial', 'm_ledgersingle')
ORDER BY user_id, company_code, branch_code, menu_code;
