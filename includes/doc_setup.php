<?php
/**
 * includes/doc_setup.php — shared document-type lookups for data-entry modules.
 *
 *   doc_master       → default doc_type for a doc_code
 *   doc_type_master  → doc_types defined for a doc_code (with names)
 *   document_setup   → which of those doc_types are enabled for data entry
 *
 * Every lookup is scoped to the logged-in company_code and branch_code.
 */

if (!function_exists('erp_doc_configured_types')) {
    /**
     * Doc types defined in doc_type_master AND enabled in document_setup.
     * Returns [doc_type => doc_name], ordered by doc_type.
     */
    function erp_doc_configured_types(PDO $con, $company_code, $branch_code, $doc_code)
    {
        $types = [];
        $stmt = $con->prepare(
            "SELECT m.doc_type, m.doc_name
             FROM doc_type_master m
             INNER JOIN document_setup s
                ON s.company_code = m.company_code
               AND s.branch_code  = m.branch_code
               AND s.doc_code     = m.doc_code
               AND s.doc_type     = m.doc_type
             WHERE m.company_code = ? AND m.branch_code = ? AND m.doc_code = ?
             ORDER BY m.doc_type ASC"
        );
        $stmt->execute([$company_code, $branch_code, $doc_code]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $type = strtoupper(trim($row['doc_type']));
            $types[$type] = trim((string) $row['doc_name']);
        }
        return $types;
    }
}

if (!function_exists('erp_doc_master_type')) {
    /** Default doc_type for a doc_code as defined in doc_master ('' if none). */
    function erp_doc_master_type(PDO $con, $company_code, $branch_code, $doc_code)
    {
        $stmt = $con->prepare(
            "SELECT TOP 1 doc_type FROM doc_master
             WHERE company_code = ? AND branch_code = ? AND doc_code = ?"
        );
        $stmt->execute([$company_code, $branch_code, $doc_code]);
        return strtoupper(trim((string) $stmt->fetchColumn()));
    }
}

if (!function_exists('erp_doc_default_type')) {
    /**
     * The doc_master default if it is enabled for data entry, otherwise the
     * first enabled doc_type ('' if nothing is enabled).
     */
    function erp_doc_default_type(PDO $con, $company_code, $branch_code, $doc_code, array $configured_types)
    {
        $master_type = erp_doc_master_type($con, $company_code, $branch_code, $doc_code);
        if ($master_type !== '' && isset($configured_types[$master_type])) {
            return $master_type;
        }
        return empty($configured_types) ? '' : (string) array_key_first($configured_types);
    }
}

if (!function_exists('erp_doc_type_name')) {
    /** doc_name from doc_type_master, falling back to the doc_type code itself. */
    function erp_doc_type_name(PDO $con, $company_code, $branch_code, $doc_code, $doc_type)
    {
        $stmt = $con->prepare(
            "SELECT doc_name FROM doc_type_master
             WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ?"
        );
        $stmt->execute([$company_code, $branch_code, $doc_code, $doc_type]);
        $name = trim((string) $stmt->fetchColumn());
        return $name !== '' ? $name : $doc_type;
    }
}
