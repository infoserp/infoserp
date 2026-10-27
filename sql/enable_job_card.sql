-- Job Card (doc_code = JC, menu_code = m_txn_jobcard)

-- Seeds document_setup and ensures the Sales menu entry is active.

-- Run on each database (local + cloud) as needed.



-- 1) Document Setup: header/detail files under modules/job_card/

IF NOT EXISTS (

    SELECT 1 FROM document_setup

    WHERE company_code = '01' AND branch_code = '01'

      AND doc_code = 'JC' AND doc_type = 'PRJ'

)

INSERT INTO document_setup

    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)

VALUES

    ('01', '01', 'JC', 'PRJ', 'd_job_card_h_01', 'd_job_card_d_01', '');



IF NOT EXISTS (

    SELECT 1 FROM document_setup

    WHERE company_code = '01' AND branch_code = '01'

      AND doc_code = 'JC' AND doc_type = 'AMC'

)

INSERT INTO document_setup

    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)

VALUES

    ('01', '01', 'JC', 'AMC', 'd_job_card_h_01', 'd_job_card_d_01', '');



-- Also seed for any other company/branch that already has JC in doc_master / doc_number

INSERT INTO document_setup

    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)

SELECT

    dn.company_code,

    dn.branch_code,

    dn.doc_code,

    dn.doc_type,

    'd_job_card_h_01',

    'd_job_card_d_01',

    ''

FROM doc_number dn

WHERE dn.doc_code = 'JC'

  AND NOT EXISTS (

        SELECT 1

        FROM   document_setup s

        WHERE  s.company_code = dn.company_code

          AND  s.branch_code  = dn.branch_code

          AND  s.doc_code     = dn.doc_code

          AND  s.doc_type     = dn.doc_type

  );



-- 2) Ensure Job Card menu is active + dashboard icon enabled
UPDATE dbo.user_menu
SET    active = 'Y',
       icon   = 'Y'
WHERE  menu_code = 'm_txn_jobcard'
  AND  (ISNULL(active, 'N') <> 'Y' OR ISNULL(icon, 'N') <> 'Y');

-- 3) Currency code width (4 chars) on Job Card tables
IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'job_card_h' AND COLUMN_NAME = 'currency_code'
      AND CHARACTER_MAXIMUM_LENGTH < 4
)
    ALTER TABLE job_card_h ALTER COLUMN currency_code varchar(4) NULL;

IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'job_card_d' AND COLUMN_NAME = 'currency_code'
      AND CHARACTER_MAXIMUM_LENGTH < 4
)
    ALTER TABLE job_card_d ALTER COLUMN currency_code varchar(4) NULL;

-- Verify
SELECT * FROM document_setup WHERE doc_code = 'JC' ORDER BY company_code, branch_code, doc_type;

SELECT company_code, branch_code, user_id, menu_code, menu_name, parent_code, sort_order, active, icon
FROM   dbo.user_menu
WHERE  menu_code = 'm_txn_jobcard'
ORDER BY user_id, company_code, branch_code;

