<?php
/**
 * Variable Terms persistence — shared across document modules.
 * UI lives in shared/variable_terms.php; storage table is doc_h_terms.
 */
if (!function_exists('erpVariableTermsPersist')) {
    /**
     * Replace doc_h_terms rows for one document from a JSON array (or array) of lines.
     * Each line: process_code, remark, line_no, line_hdr, line_bold, line_italics,
     *            line_underline, line_left, line_big
     */
    function erpVariableTermsPersist(PDO $con, $company_code, $branch_code, $doc_no, $doc_type, $lines_json, $user = '')
    {
        $doc_no = trim((string) $doc_no);
        $doc_type = strtoupper(trim((string) $doc_type));
        if ($doc_no === '' || $doc_type === '') {
            return;
        }
        $lines = is_array($lines_json) ? $lines_json : json_decode((string) $lines_json, true);
        if (!is_array($lines)) {
            return;
        }

        $stmt_del = $con->prepare(
            "DELETE FROM doc_h_terms
             WHERE company_code = ? AND branch_code = ? AND doc_no = ? AND doc_type = ?"
        );
        $stmt_del->execute([$company_code, $branch_code, $doc_no, $doc_type]);

        if (empty($lines)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO doc_h_terms (
                    company_code, branch_code, doc_no, doc_type,
                    serial_no, line_no, process_code, remark,
                    line_hdr, line_bold, line_italics, line_underline, line_left, line_big,
                    user_id, user_date, last_userid, last_userdate
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $con->prepare($sql);
        foreach ($lines as $idx => $line) {
            if (!is_array($line)) {
                continue;
            }
            $yn = function ($v) {
                return (strtoupper(trim((string) $v)) === 'Y' || $v === true || $v === 1 || $v === '1') ? 'Y' : 'N';
            };
            $process = trim((string) ($line['process_code'] ?? ''));
            $remark  = trim((string) ($line['remark'] ?? ''));
            if ($process === '' && $remark === '') {
                continue;
            }
            $serial = str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT);
            $stmt->execute([
                $company_code,
                $branch_code,
                $doc_no,
                $doc_type,
                $serial,
                trim((string) ($line['line_no'] ?? '')),
                $process !== '' ? $process : null,
                $remark !== '' ? $remark : null,
                $yn($line['line_hdr'] ?? 'N'),
                $yn($line['line_bold'] ?? 'N'),
                $yn($line['line_italics'] ?? 'N'),
                $yn($line['line_underline'] ?? 'N'),
                $yn($line['line_left'] ?? 'N'),
                $yn($line['line_big'] ?? 'N'),
                $user !== '' ? $user : null,
                $now,
                $user !== '' ? $user : null,
                $now,
            ]);
        }
    }
}
