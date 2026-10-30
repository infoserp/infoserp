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

if (!function_exists('erp_print_wrap_lines')) {
    /** Split text into display lines using newlines + word-wrap. */
    function erp_print_wrap_lines($text, $wrap_chr)
    {
        $wrap_chr = max(8, (int) $wrap_chr);
        $out = [];
        foreach (preg_split("/\r\n|\r|\n/", (string) $text) as $para) {
            $wrapped = wordwrap($para !== '' ? $para : ' ', $wrap_chr, "\n", true);
            foreach (explode("\n", $wrapped) as $line) {
                $out[] = $line;
            }
        }
        return $out ?: [''];
    }
}

if (!function_exists('erp_print_paginate_detail_rows')) {
    /**
     * Paginate detail rows so long description/remark text can span multiple A4 pages.
     * Even a single oversized line is split; the last page keeps room for totals/terms/sign.
     *
     * Options: area_mm, first_area_mm, last_area_mm, first_last_area_mm, row_mm, wrap_mm, wrap_chr
     * first_area_mm = page 1 capacity when header boxes are shown.
     * last_area_mm = last-page capacity when boxes are omitted (continuation last page).
     * first_last_area_mm = capacity when page 1 is also the last page (boxes + footer).
     *
     * @return array{0: list<list<array>>, 1: list<float>} pages and per-page mm
     */
    function erp_print_paginate_detail_rows(array $rows, array $opt)
    {
        $area       = (float) ($opt['area_mm'] ?? 100);
        $first_area = (float) ($opt['first_area_mm'] ?? $area);
        $last_area  = (float) ($opt['last_area_mm'] ?? 55);
        $first_last = (float) ($opt['first_last_area_mm'] ?? min($first_area, $last_area));
        $row_mm     = (float) ($opt['row_mm'] ?? 5.5);
        $wrap_mm    = (float) ($opt['wrap_mm'] ?? 4);
        $wrap_chr   = (int) ($opt['wrap_chr'] ?? 55);
        if ($area < $row_mm) {
            $area = $row_mm;
        }
        if ($first_area < $row_mm) {
            $first_area = $row_mm;
        }
        if ($first_area > $area) {
            $first_area = $area;
        }
        if ($last_area > $area) {
            $last_area = $area;
        }
        if ($last_area < $row_mm) {
            $last_area = $row_mm;
        }
        if ($first_last > $first_area) {
            $first_last = $first_area;
        }
        if ($first_last < $row_mm) {
            $first_last = $row_mm;
        }

        $max_lines_for = function ($max_mm) use ($row_mm, $wrap_mm) {
            if ($max_mm <= $row_mm) {
                return 1;
            }
            return 1 + (int) floor(($max_mm - $row_mm) / max(0.1, $wrap_mm));
        };
        $mm_for_lines = function ($n) use ($row_mm, $wrap_mm) {
            $n = max(1, (int) $n);
            return $row_mm + ($n - 1) * $wrap_mm;
        };
        $page_cap = function ($page_index) use ($first_area, $area) {
            return $page_index === 0 ? $first_area : $area;
        };

        // Expand each source row into fragments that each fit in the largest page area
        $fragments = [];
        foreach ($rows as $row) {
            $desc_lines = erp_print_wrap_lines($row['desc'] ?? '', $wrap_chr);
            $note_lines = [];
            if (trim((string) ($row['note'] ?? '')) !== '') {
                $note_lines = erp_print_wrap_lines($row['note'], $wrap_chr);
            }
            $all = [];
            foreach ($desc_lines as $s) {
                $all[] = ['k' => 'd', 's' => $s];
            }
            foreach ($note_lines as $s) {
                $all[] = ['k' => 'n', 's' => $s];
            }
            if (!$all) {
                $all[] = ['k' => 'd', 's' => ''];
            }

            $max_lines = $max_lines_for($area);
            $offset = 0;
            $first = true;
            while ($offset < count($all)) {
                $slice = array_slice($all, $offset, $max_lines);
                $offset += count($slice);
                $dparts = [];
                $nparts = [];
                foreach ($slice as $piece) {
                    if ($piece['k'] === 'd') {
                        $dparts[] = $piece['s'];
                    } else {
                        $nparts[] = $piece['s'];
                    }
                }
                $frag = $row;
                $frag['desc'] = implode("\n", $dparts);
                $frag['note'] = implode("\n", $nparts);
                $frag['show_values'] = $first;
                $frag['continued'] = !$first;
                $frag['mm'] = $mm_for_lines(count($slice));
                $fragments[] = $frag;
                $first = false;
            }
        }

        $lines_of = function (array $frag) use ($wrap_chr) {
            $desc_lines = erp_print_wrap_lines($frag['desc'] ?? '', $wrap_chr);
            $note_lines = ($frag['note'] ?? '') !== '' ? erp_print_wrap_lines($frag['note'], $wrap_chr) : [];
            $all = [];
            foreach ($desc_lines as $s) {
                $all[] = ['k' => 'd', 's' => $s];
            }
            foreach ($note_lines as $s) {
                $all[] = ['k' => 'n', 's' => $s];
            }
            return $all ?: [['k' => 'd', 's' => '']];
        };
        $frag_from_slice = function (array $base, array $slice, $show, $continued) use ($mm_for_lines) {
            $dparts = [];
            $nparts = [];
            foreach ($slice as $piece) {
                if ($piece['k'] === 'd') {
                    $dparts[] = $piece['s'];
                } else {
                    $nparts[] = $piece['s'];
                }
            }
            $out = $base;
            $out['desc'] = implode("\n", $dparts);
            $out['note'] = implode("\n", $nparts);
            $out['show_values'] = $show;
            $out['continued'] = $continued;
            $out['mm'] = $mm_for_lines(count($slice));
            return $out;
        };

        // Pack fragments; page 1 uses first_area (header boxes), later pages use area
        $pages = [[]];
        $page_mm = [0.0];
        foreach ($fragments as $frag) {
            $queue = [$frag];
            while ($queue) {
                $cur = array_shift($queue);
                $p = count($pages) - 1;
                $cap = $page_cap($p);
                $remaining = $cap - $page_mm[$p];

                if ($pages[$p] && $cur['mm'] > $remaining + 0.01) {
                    $pages[] = [];
                    $page_mm[] = 0.0;
                    array_unshift($queue, $cur);
                    continue;
                }

                if ($cur['mm'] > $cap + 0.01) {
                    // Split to fit this page's smaller first_area (or any tight cap)
                    $all = $lines_of($cur);
                    $keep = max(1, $max_lines_for($cap));
                    $head = array_slice($all, 0, $keep);
                    $tail = array_slice($all, $keep);
                    $pages[$p][] = $frag_from_slice($cur, $head, !empty($cur['show_values']), !empty($cur['continued']));
                    $page_mm[$p] += $pages[$p][count($pages[$p]) - 1]['mm'];
                    if ($tail) {
                        $queue[] = $frag_from_slice($cur, $tail, false, true);
                    }
                    continue;
                }

                $pages[$p][] = $cur;
                $page_mm[$p] += $cur['mm'];
            }
        }

        // Ensure last page leaves room for totals / terms / signature.
        // Page 1-as-last uses first_last (boxes + footer); later last pages use last_area.
        $guard = 0;
        while ($guard++ < 500) {
            $p = count($pages) - 1;
            $need = ($p === 0) ? $first_last : $last_area;
            if ($page_mm[$p] <= $need + 0.01) {
                break;
            }
            if (count($pages[$p]) <= 1) {
                $pages[] = [];
                $page_mm[] = 0.0;
                break;
            }
            $moved = array_pop($pages[$p]);
            $page_mm[$p] -= $moved['mm'];
            if ($page_mm[$p] < 0) {
                $page_mm[$p] = 0;
            }
            $pages[] = [$moved];
            $page_mm[] = $moved['mm'];
        }

        if (!$pages) {
            $pages = [[]];
            $page_mm = [0.0];
        }
        return [$pages, $page_mm];
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
            throw new RuntimeException(
                'PDF library missing: run "composer install" on the server (the vendor/ folder is not in git).'
            );
        }
        require_once $autoload;

        if (!class_exists('\\Dompdf\\Dompdf')) {
            throw new RuntimeException('Dompdf is not available. Check that vendor/dompdf is installed.');
        }
        if (PHP_VERSION_ID < 70200) {
            // Current Dompdf 3.x pulls sabberworm/php-css-parser which needs PHP 7.2+
            throw new RuntimeException(
                'PDF needs PHP 7.2 or newer (server is ' . PHP_VERSION . '). Use browser Print, or upgrade PHP.'
            );
        }

        $css  = (string) @file_get_contents(dirname(__DIR__) . '/assets/css/erp_print.css');
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head>'
              . '<body class="erp-print-pdf">' . $sheets_html . '</body></html>';

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        // Dompdf 2 used a string chroot; 3 accepts array — keep string for wider compatibility
        $options->set('chroot', dirname(__DIR__));
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
