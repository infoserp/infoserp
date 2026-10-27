-- Enable Master > Customer (menu_code = m_ma-customer) in user_menu.
-- Parent is menu_name 'Master' (menu_code m_master). No document_setup required.
-- Run on each database (local + cloud) as needed.

-- 1) Ensure Master parent is active
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_master'
  AND  ISNULL(active, 'N') <> 'Y';

-- 2) Turn on Customer for users who already have the menu row
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_ma-customer'
  AND  ISNULL(active, 'N') <> 'Y';

-- 3) Insert Customer for users who have Sales Invoice but are missing Customer
INSERT INTO dbo.user_menu (
    company_code, branch_code, user_id,
    menu_code, menu_name, menu_name_arabic, icon_name,
    parent_code, sort_order, menu_level, active, icon
)
SELECT
    si.company_code,
    si.branch_code,
    si.user_id,
    'm_ma-customer',
    'Customer',
    N'',
    N'',
    'Master',
    '009',
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
          AND  x.menu_code    = 'm_ma-customer'
  );

-- 4) Ensure those users also have an active Master parent row
INSERT INTO dbo.user_menu (
    company_code, branch_code, user_id,
    menu_code, menu_name, menu_name_arabic, icon_name,
    parent_code, sort_order, menu_level, active, icon
)
SELECT
    c.company_code,
    c.branch_code,
    c.user_id,
    'm_master',
    'Master',
    N'',
    N'',
    'Root',
    '001',
    NULL,
    'Y',
    'N'
FROM dbo.user_menu c
WHERE c.menu_code = 'm_ma-customer'
  AND NOT EXISTS (
        SELECT 1
        FROM   dbo.user_menu x
        WHERE  x.company_code = c.company_code
          AND  x.branch_code  = c.branch_code
          AND  x.user_id      = c.user_id
          AND  x.menu_code    = 'm_master'
  );

-- Verify
SELECT company_code, branch_code, user_id, menu_code, menu_name, parent_code, sort_order, active
FROM   dbo.user_menu
WHERE  menu_code IN ('m_master', 'm_ma-customer')
ORDER BY user_id, company_code, branch_code, menu_code;
