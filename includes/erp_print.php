<?php
/**
 * Common print-preview helpers — used by shared/print_preview.php and every
 * modules/<module>/print_format/<print_file>.php.
 *
 *   erp_print_format_path() .. print format file configured in document_setup.print_file
 *   erp_print_company() ...... company_master header (name + address line)
 *   erp_print_company_head() . common company header + document title block
 *   erp_print_pages() ........ split detail rows into A4 pages
 *   erp_print_qty() / erp_print_amount() / erp_print_date() .. value formatting
 *   erp_print_amount_words() . amount in words (e.g. "AED ONE HUNDRED AND FILS TEN ONLY")
 *   erp_print_image_data_uri() letter header / footer image as a data URI (blank when unreachable)
 *   erp_print_pdf() .......... stream the rendered sheets as an A4 PDF (Dompdf)
 */

if (!function_exists('erp_print_format_path')) {
    function erp_print_format_path($con, $company_code, $branch_code, $doc_code, $doc_type)
    {
        $stmt = $con->prepare(
            "SELECT print_file FROM document_setup
             WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ?"
        );
        $stmt->execute([$company_code, $branch_code, $doc_code, $doc_type]);
        $file = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) $stmt->fetchColumn()));
        if ($file === '') {
            return '';
        }
        $matches = glob(dirname(__DIR__) . '/modules/*/print_format/' . $file . '.php') ?: [];
        return $matches ? $matches[0] : '';
    }
}

if (!function_exists('erp_print_company')) {
    function erp_print_company($con, $company_code)
    {
        $stmt = $con->prepare(
            "SELECT company_name, po_box, street, city, country, tel, address
             FROM company_master WHERE company_code = ?"
        );
        $stmt->execute([$company_code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $address = trim((string) ($row['address'] ?? ''));
        if ($address === '') {
            $address = implode(' ', array_filter([
                trim((string) ($row['po_box'] ?? '')),
                trim((string) ($row['street'] ?? '')),
                trim((string) ($row['city'] ?? '')),
                trim((string) ($row['country'] ?? '')),
            ], 'strlen'));
        }

        return [
            'name'    => trim((string) ($row['company_name'] ?? '')),
            'address' => implode(' ', array_filter([$address, trim((string) ($row['tel'] ?? ''))], 'strlen')),
        ];
    }
}

if (!function_exists('erp_print_company_head')) {
    function erp_print_company_head(array $company, $title)
    {
        return '<div class="pf-company">'
             .   '<div class="pf-company-name">' . htmlspecialchars($company['name'] ?? '') . '</div>'
             .   '<div class="pf-company-addr">' . htmlspecialchars($company['address'] ?? '') . '</div>'
             . '</div>'
             . '<div class="pf-title"><span>' . htmlspecialchars($title) . '</span></div>';
    }
}

if (!function_exists('erp_print_pages')) {
    function erp_print_pages(array $rows, $per_page)
    {
        $pages = array_chunk($rows, max(1, (int) $per_page));
        return $pages ?: [[]];
    }
}

if (!function_exists('erp_print_qty')) {
    function erp_print_qty($value)
    {
        $text = number_format((float) $value, 3, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }
}

if (!function_exists('erp_print_amount')) {
    function erp_print_amount($value, $decimals = 2)
    {
        return number_format((float) $value, $decimals);
    }
}

if (!function_exists('erp_print_date')) {
    function erp_print_date($value)
    {
        if (empty($value)) {
            return '';
        }
        try {
            return (new DateTime($value))->format('d/m/Y');
        } catch (Exception $e) {
            return trim((string) $value);
        }
    }
}

if (!function_exists('erp_print_number_words')) {
    function erp_print_number_words($number)
    {
        $number = (int) $number;
        $ones = ['ZERO', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN',
                 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
        $tens = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
        if ($number < 20) {
            return $ones[$number];
        }
        if ($number < 100) {
            return $tens[intdiv($number, 10)] . ($number % 10 ? ' ' . $ones[$number % 10] : '');
        }
        if ($number < 1000) {
            return $ones[intdiv($number, 100)] . ' HUNDRED' . ($number % 100 ? ' ' . erp_print_number_words($number % 100) : '');
        }
        foreach ([1000000000 => 'BILLION', 1000000 => 'MILLION', 1000 => 'THOUSAND'] as $size => $name) {
            if ($number >= $size) {
                return erp_print_number_words(intdiv($number, $size)) . ' ' . $name
                     . ($number % $size ? ' ' . erp_print_number_words($number % $size) : '');
            }
        }
        return '';
    }
}

if (!function_exists('erp_print_amount_words')) {
    /**
     * "AED ONE HUNDRED SIXTY SEVEN AND FILS SEVENTY FOUR ONLY"
     * Without a fraction name the decimals are written as nn/100.
     */
    function erp_print_amount_words($amount, $currency_label, $fraction_label = '')
    {
        $cents = (int) round(abs((float) $amount) * 100);
        $whole = intdiv($cents, 100);
        $fraction = $cents % 100;
        $text = trim($currency_label . ' ' . erp_print_number_words($whole));
        if ($fraction > 0) {
            $text .= trim($fraction_label) !== ''
                ? ' AND ' . strtoupper(trim($fraction_label)) . ' ' . erp_print_number_words($fraction)
                : ' AND ' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) . '/100';
        }
        return $text . ' ONLY';
    }
}

if (!function_exists('erp_print_host_reachable')) {
    // Quick SMB (port 445) check, cached per request: an unreachable share would otherwise
    // block is_file() past max_execution_time and the preview comes back empty.
    function erp_print_host_reachable($host, $timeout = 1.5)
    {
        static $cache = [];
        $host = strtolower(trim((string) $host));
        if (!isset($cache[$host])) {
            $fp = @fsockopen($host, 445, $errno, $errstr, $timeout);
            $cache[$host] = (bool) $fp;
            if ($fp) {
                fclose($fp);
            }
        }
        return $cache[$host];
    }
}

if (!function_exists('erp_print_image_data_uri')) {
    /**
     * Image file (local, UNC or file:// path) as a data URI for the sheets; '' when the path is
     * empty, the file is missing, or its network share can't be reached.
     */
    function erp_print_image_data_uri($path)
    {
        require_once __DIR__ . '/do_print_service.php';
        $path = doPrintNormalizeFsPath($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^\\\\\\\\([^\\\\]+)\\\\#', $path, $m) && !erp_print_host_reachable($m[1])) {
            return '';
        }
        return doPrintImageDataUriFromPath($path);
    }
}

if (!function_exists('erp_print_pdf')) {
    function erp_print_pdf($sheets_html, $doc_no)
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('PDF library (vendor folder) is not installed on this server.');
        }
        require_once $autoload;

        $css  = (string) @file_get_contents(dirname(__DIR__) . '/assets/css/erp_print.css');
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head>'
              . '<body class="erp-print-pdf">' . $sheets_html . '</body></html>';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', [dirname(__DIR__)]);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', trim((string) $doc_no));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $dompdf->stream(($name !== '' ? $name : 'document') . '.pdf', ['Attachment' => true]);
        exit;
    }
}
