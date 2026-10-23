-- =============================================================================
-- Create document_setup table for Approval ERP
-- Run this script on the "franz" database (SQL Server Management Studio)
-- as a user with CREATE TABLE permission (e.g. dbo / sa).
--
-- Error fixed:
--   Invalid object name 'document_setup'
--   (invoice.php, receipt.php, document_setup.php)
-- =============================================================================

IF NOT EXISTS (
    SELECT 1 FROM sys.objects
    WHERE object_id = OBJECT_ID(N'[dbo].[document_setup]') AND type = 'U'
)
BEGIN
    CREATE TABLE [dbo].[document_setup] (
        [id]           INT IDENTITY(1,1) NOT NULL,
        [company_code] VARCHAR(10)  NOT NULL,
        [branch_code]  VARCHAR(10)  NOT NULL,
        [doc_code]     VARCHAR(10)  NOT NULL,
        [doc_type]     VARCHAR(20)  NOT NULL,
        [header_file]  VARCHAR(200) NOT NULL,
        [detail_file]  VARCHAR(200) NOT NULL,
        [print_file]   VARCHAR(200) NULL,
        CONSTRAINT [PK_document_setup] PRIMARY KEY CLUSTERED ([id] ASC),
        CONSTRAINT [UQ_document_setup_code_type]
            UNIQUE ([company_code], [branch_code], [doc_code], [doc_type])
    );
END
GO

-- Grant SELECT/INSERT/UPDATE/DELETE to the ERP web login if needed.
-- Replace [YourErpLogin] with the SQL login used by the PHP app.
/*
GRANT SELECT, INSERT, UPDATE, DELETE ON [dbo].[document_setup] TO [YourErpLogin];
GO
*/

-- =============================================================================
-- Sample configuration rows
-- Replace company_code / branch_code with your actual session values
-- (check branchselection.php / login session — often '01' / '01').
-- =============================================================================

-- Sales Invoice — Credit Sales (modules/invoice/)
IF NOT EXISTS (
    SELECT 1 FROM document_setup
    WHERE company_code = '01' AND branch_code = '01'
      AND doc_code = 'SI' AND doc_type = 'CRS'
)
INSERT INTO document_setup
    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)
VALUES
    ('01', '01', 'SI', 'CRS', 'sales_invoice_h_01', 'sales_invoice_d_01', '');

-- Receipt Voucher — Bank Receipt (modules/receipt/)
IF NOT EXISTS (
    SELECT 1 FROM document_setup
    WHERE company_code = '01' AND branch_code = '01'
      AND doc_code = 'RV' AND doc_type = 'BRV'
)
INSERT INTO document_setup
    (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)
VALUES
    ('01', '01', 'RV', 'BRV', 'd_chq_receipt_h_01', 'd_chq_receipt_d_01', '');

GO

-- Verify
SELECT * FROM document_setup ORDER BY doc_code, doc_type;
