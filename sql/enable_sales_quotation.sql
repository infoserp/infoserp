-- Sales Quotation (doc_code = SQ, menu_code = m_txn_salesquotation)
-- Seeds document_setup and ensures the Sales menu entry is active.
-- Run on each database (local + cloud) as needed.

-- 1) Document Setup: header/detail files under modules/sales_quot/
IF NOT EXISTS (
    SELECT 1 FROM document_setup
    WHERE company_code = '01' AND branch_code = '01'
      AND doc_code = 'SQ' AND doc_type = 'SQN'
)
INSERT INTO document_setup
    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)
VALUES
    ('01', '01', 'SQ', 'SQN', 'd_sales_quotation_h_01', 'd_sales_quotation_d_01', '');

-- Also seed for any other company/branch that already has SQ in doc_master
INSERT INTO document_setup
    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)
SELECT
    dm.company_code,
    dm.branch_code,
    dm.doc_code,
    dm.doc_type,
    'd_sales_quotation_h_01',
    'd_sales_quotation_d_01',
    ''
FROM doc_master dm
WHERE dm.doc_code = 'SQ'
  AND NOT EXISTS (
        SELECT 1
        FROM   document_setup s
        WHERE  s.company_code = dm.company_code
          AND  s.branch_code  = dm.branch_code
          AND  s.doc_code     = dm.doc_code
          AND  s.doc_type     = dm.doc_type
  );

-- 2) Ensure Sales Quotation menu is active where the row already exists
UPDATE dbo.user_menu
SET    active = 'Y'
WHERE  menu_code = 'm_txn_salesquotation'
  AND  ISNULL(active, 'N') <> 'Y';

-- Verify
SELECT * FROM document_setup WHERE doc_code = 'SQ' ORDER BY company_code, branch_code, doc_type;

SELECT company_code, branch_code, user_id, menu_code, menu_name, parent_code, sort_order, active
FROM   dbo.user_menu
WHERE  menu_code = 'm_txn_salesquotation'
ORDER BY user_id, company_code, branch_code;
