<?php
/**
 * DO print data, PDF generation, storage path, and customer email helpers.
 */

if (!function_exists('doPrintEnsureVendorAutoload')) {
    function doPrintEnsureVendorAutoload()
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $autoload = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException(
                'Composer vendor folder is missing on this server. Deploy the vendor directory or run composer install.'
            );
        }

        require_once $autoload;
        $loaded = true;
    }
}

if (!function_exists('doPrintFmtDate')) {
    function doPrintFmtDate($d)
    {
        return !empty($d) ? date('d/m/Y', strtotime($d)) : '—';
    }
}

if (!function_exists('doPrintSafe')) {
    function doPrintSafe($v)
    {
        return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('doPrintStripCssMediaBlock')) {
    function doPrintStripCssMediaBlock($css, $media)
    {
        $pattern = '/@media\s+' . preg_quote($media, '/') . '\s*\{/s';
        if (!preg_match($pattern, $css, $m, PREG_OFFSET_CAPTURE)) {
            return $css;
        }

        $start = $m[0][1];
        $pos   = $start + strlen($m[0][0]);
        $depth = 1;
        $len   = strlen($css);

        while ($pos < $len && $depth > 0) {
            if ($css[$pos] === '{') {
                $depth++;
            } elseif ($css[$pos] === '}') {
                $depth--;
            }
            $pos++;
        }

        return substr($css, 0, $start) . substr($css, $pos);
    }
}

if (!function_exists('doPrintStylesCss')) {
    function doPrintStylesCss($forPdf = false)
    {
        static $cssScreen = null;
        static $cssPdf    = null;

        if ($forPdf && $cssPdf !== null) {
            return $cssPdf;
        }
        if (!$forPdf && $cssScreen !== null) {
            return $cssScreen;
        }

        $file = __DIR__ . '/../modules/do_dispatch/do_print.php';
        $src  = @file_get_contents($file);
        if (!$src || !preg_match('/<style>\s*(.*?)\s*<\/style>/s', $src, $m)) {
            throw new RuntimeException('Could not load DO print stylesheet.');
        }

        $css = $m[1];
        if ($forPdf) {
            // Drop browser @media blocks — Dompdf uses print media and these rules
            // cause overflow, tfoot pagination, and extra blank pages.
            $css = doPrintStripCssMediaBlock($css, 'print');
            $css = doPrintStripCssMediaBlock($css, 'screen');
            // Fixed 210mm width overflows the PDF content area (page width minus margins).
            $css = preg_replace(
                '/\.a4-paper\s*\{[^}]*\}/s',
                '.a4-paper { width: 100%; margin: 0; background: #ffffff; position: static; box-shadow: none; }',
                $css,
                1
            );
            $css = preg_replace(
                '/\.doc-content\s*\{[^}]*\}/s',
                '.doc-content { padding: 0; }',
                $css,
                1
            );
            $cssPdf = $css;
            return $cssPdf;
        }

        $cssScreen = $css;
        return $cssScreen;
    }
}

if (!function_exists('doPrintLoadData')) {
    function doPrintLoadData(PDO $con, $company_code, $branch_code, $doc_no)
    {
        $doc_no = trim((string) $doc_no);
        if ($doc_no === '') {
            throw new InvalidArgumentException('Missing delivery order number.');
        }

        $h_sql = "SELECT
                      dnh.doc_no,
                      dnh.doc_dt,
                      dnh.ac_code,
                      dnh.po_no,
                      dnh.despatch,
                      dnh.despatch_to,
                      dnh.despatch_date,
                      dnh.receiver_name,
                      dnh.receiver_contact,
                      dnh.receiver_signature_path,
                      dnh.signature_date,
                      dnh.receiver_latitude,
                      dnh.receiver_longitude,
                      dnh.receiver_location_accuracy,
                      dnh.receiver_location_address,
                      dnh.receiver_location_captured_at,
                      am.description  AS customer_name,
                      am.po_box,
                      am.city,
                      am.country,
                      am.tel_off      AS customer_tel,
                      am.tax_no       AS customer_trn
                  FROM  delivery_note_h dnh
                  LEFT JOIN ac_master am
                      ON  am.ac_code      = dnh.ac_code
                      AND am.company_code = dnh.company_code
                  WHERE dnh.doc_no       = :doc_no
                    AND dnh.company_code = :company_code
                    AND dnh.branch_code  = :branch_code";

        $stmt = $con->prepare($h_sql);
        $stmt->execute([
            ':doc_no'       => $doc_no,
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
        ]);
        $do_header = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$do_header) {
            throw new RuntimeException('Delivery Order not found: ' . $doc_no);
        }

        $d_sql = "SELECT
                      serial_no,
                      item_code,
                      item_description,
                      item_qty,
                      item_unit,
                      job_no
                  FROM  delivery_note_d
                  WHERE doc_no       = :doc_no
                    AND company_code = :company_code
                    AND branch_code  = :branch_code
                  ORDER BY serial_no ASC";

        $dstmt = $con->prepare($d_sql);
        $dstmt->execute([
            ':doc_no'       => $doc_no,
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
        ]);
        $do_lines = $dstmt->fetchAll(PDO::FETCH_ASSOC);

        $job_no = '';
        foreach ($do_lines as $dl) {
            if (!empty($dl['job_no'])) {
                $job_no = $dl['job_no'];
                break;
            }
        }

        $bm_sql = "SELECT
                       bm.branch_name,
                       bm.tax_no      AS company_trn,
                       bm.header_filepath,
                       bm.footer_filepath
                   FROM  branch_master bm
                   WHERE bm.company_code = :company_code
                     AND bm.branch_code  = :branch_code";
        $bmstmt = $con->prepare($bm_sql);
        $bmstmt->execute([':company_code' => $company_code, ':branch_code' => $branch_code]);
        $branch_info = $bmstmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'doc_no'      => $doc_no,
            'do_header'   => $do_header,
            'do_lines'    => $do_lines,
            'branch_info' => $branch_info,
            'job_no'      => $job_no,
        ];
    }
}

if (!function_exists('doPrintFetchCompanyName')) {
    function doPrintFetchCompanyName(PDO $con, $company_code)
    {
        $company_code = trim((string) $company_code);
        if ($company_code === '') {
            return '';
        }

        $stmt = $con->prepare(
            "SELECT company_name
             FROM   company_master
             WHERE  company_code = :company_code"
        );
        $stmt->execute([':company_code' => $company_code]);
        $name = trim((string) ($stmt->fetchColumn() ?: ''));

        return $name;
    }
}

if (!function_exists('doPrintNormalizeFsPath')) {
    /**
     * Normalize UNC / file:// paths so PHP can read them from disk.
     * Example: file://fandoesserver/infos/... -> \\fandoesserver\infos\...
     */
    function doPrintNormalizeFsPath($path)
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^file:/+#i', $path)) {
            $path = preg_replace('#^file:/+#i', '', $path);
            $path = str_replace('/', '\\', $path);
            if (strpos($path, '\\\\') !== 0) {
                $path = '\\\\' . ltrim($path, '\\');
            }
            return $path;
        }

        // Keep UNC as-is (allow both \\server\share and //server/share).
        if (strpos($path, '//') === 0) {
            $path = '\\\\' . str_replace('/', '\\', substr($path, 2));
        }

        return $path;
    }
}

if (!function_exists('doPrintImageMimeFromPath')) {
    function doPrintImageMimeFromPath($path)
    {
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'gif') {
            return 'image/gif';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }
        if ($ext === 'jpg' || $ext === 'jpeg') {
            return 'image/jpeg';
        }
        return 'image/jpeg';
    }
}

if (!function_exists('doPrintImageDataUriFromPath')) {
    /**
     * Read image from local/UNC path and return data URI for browser <img src>.
     * Browsers block file:// and \\server\share in <img>, so this is required for HTML print.
     */
    function doPrintImageDataUriFromPath($path)
    {
        $path = doPrintNormalizeFsPath($path);
        if ($path === '' || !@is_file($path) || !@is_readable($path)) {
            return '';
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        return 'data:' . doPrintImageMimeFromPath($path) . ';base64,' . base64_encode($bytes);
    }
}

if (!function_exists('doPrintPrepareViewVars')) {
    function doPrintPrepareViewVars(array $data, array $options = [])
    {
        $forPdf     = !empty($options['for_pdf']);
        $moduleDir  = realpath(__DIR__ . '/../modules/do_dispatch');
        $do_header  = $data['do_header'];
        $branch_info = $data['branch_info'] ?? [];

        $branch_name = !empty($branch_info['branch_name'])
            ? $branch_info['branch_name']
            : ($_SESSION['branch_name'] ?? '');

        $receiver_name    = trim($do_header['receiver_name'] ?? '');
        $receiver_contact = trim($do_header['receiver_contact'] ?? '');

        $sig_src = '';
        $sig_path_db = trim($do_header['receiver_signature_path'] ?? '');
        if ($sig_path_db !== '' && $moduleDir) {
            $sig_file = $moduleDir . DIRECTORY_SEPARATOR . 'uploads'
                      . DIRECTORY_SEPARATOR . 'signatures'
                      . DIRECTORY_SEPARATOR . basename($sig_path_db);
            if (is_file($sig_file)) {
                if ($forPdf) {
                    $mime = 'image/png';
                    $sig_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($sig_file));
                } else {
                    $sig_src = 'uploads/signatures/' . rawurlencode(basename($sig_path_db));
                }
            }
        }

        $sig_ts = !empty($do_header['signature_date'])
            ? date('M d, Y - H:i', strtotime($do_header['signature_date']))
            : date('M d, Y - H:i');

        $receiver_lat = $do_header['receiver_latitude'] ?? null;
        $receiver_lng = $do_header['receiver_longitude'] ?? null;
        $maps_url     = doPrintGoogleMapsUrl($receiver_lat, $receiver_lng);

        // Use exact header/footer image paths from branch_master.
        // If not set in DB, fall back to existing local template images.
        $headerFromDb = doPrintNormalizeFsPath($branch_info['header_filepath'] ?? '');
        $footerFromDb = doPrintNormalizeFsPath($branch_info['footer_filepath'] ?? '');

        if ($moduleDir) {
            $headerFallbackFs = $moduleDir . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . 'header.jpeg';
            $footerFallbackFs = $moduleDir . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . 'footer.jpeg';
            $headerFallbackWeb = 'image/header.jpeg';
            $footerFallbackWeb = 'image/footer.jpeg';
        } else {
            $headerFallbackFs = '';
            $footerFallbackFs = '';
            $headerFallbackWeb = 'image/header.jpeg';
            $footerFallbackWeb = 'image/footer.jpeg';
        }

        if ($forPdf) {
            // PDF canvas needs a real filesystem path (UNC is OK if PHP can read it).
            $header_img = ($headerFromDb !== '' && @is_file($headerFromDb)) ? $headerFromDb : $headerFallbackFs;
            $footer_img = ($footerFromDb !== '' && @is_file($footerFromDb)) ? $footerFromDb : $footerFallbackFs;
        } else {
            // Browser HTML print cannot use file:// or \\server\share in <img src>.
            // Convert to data URI so the browser never sees a local/UNC path.
            $header_img = '';
            $footer_img = '';
            if ($headerFromDb !== '') {
                $header_img = doPrintImageDataUriFromPath($headerFromDb);
            }
            if ($footerFromDb !== '') {
                $footer_img = doPrintImageDataUriFromPath($footerFromDb);
            }
            if ($header_img === '') {
                $header_img = $headerFallbackWeb;
            }
            if ($footer_img === '') {
                $footer_img = $footerFallbackWeb;
            }
        }

        return [
            'doc_no'              => $data['doc_no'],
            'do_header'           => $do_header,
            'do_lines'            => $data['do_lines'],
            'branch_info'         => $branch_info,
            'job_no'              => $data['job_no'] ?? '',
            'do_print_header_img' => $header_img,
            'do_print_footer_img' => $footer_img,
            'do_print_sig_src'    => $sig_src,
            'do_print_branch_name'=> $branch_name,
            'do_print_receiver_name'    => $receiver_name,
            'do_print_receiver_contact' => $receiver_contact,
            'do_print_sig_ts'     => $sig_ts,
            'do_print_maps_url'   => $maps_url,
            'do_print_for_pdf'    => $forPdf,
        ];
    }
}

if (!function_exists('doPrintGoogleMapsUrl')) {
    function doPrintGoogleMapsUrl($latitude, $longitude)
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return '';
        }
        return 'https://maps.google.com/?q=' . rawurlencode((string) $latitude . ',' . (string) $longitude);
    }
}

if (!function_exists('doPrintRenderClosingHtml')) {
    function doPrintRenderClosingHtml(array $data, array $options = [])
    {
        extract(doPrintPrepareViewVars($data, $options));
        ob_start();
        include __DIR__ . '/do_print_closing.inc.php';
        return ob_get_clean();
    }
}

if (!function_exists('doPrintFormatTotalQty')) {
    function doPrintFormatTotalQty(array $lines)
    {
        $sum = 0.0;
        $hasDecimalQty = false;

        foreach ($lines as $line) {
            $qtyRaw = trim((string) ($line['item_qty'] ?? ''));
            if ($qtyRaw === '') {
                continue;
            }
            if (strpos($qtyRaw, '.') !== false) {
                $hasDecimalQty = true;
            }
            $sum += (float) str_replace(',', '', $qtyRaw);
        }

        if ($hasDecimalQty) {
            return number_format($sum, 1, '.', '');
        }

        return (string) (abs($sum - round($sum)) < 0.00001 ? (int) round($sum) : rtrim(rtrim(number_format($sum, 4, '.', ''), '0'), '.'));
    }
}

if (!function_exists('doPrintRenderBodyHtml')) {
    function doPrintRenderBodyHtml(array $data, array $options = [])
    {
        extract(doPrintPrepareViewVars($data, $options));
        ob_start();
        include __DIR__ . '/do_print_body.inc.php';
        $html = ob_get_clean();

        $totalQty = doPrintFormatTotalQty($data['do_lines'] ?? []);
        $html = preg_replace(
            '/Total items:\s*[\d,]+/i',
            'Total item quantity: ' . doPrintSafe($totalQty),
            $html,
            1
        );

        return $html;
    }
}

if (!function_exists('doPrintPdfLayout')) {
    /**
     * Shared PDF measurements. Signature band is drawn via page_script canvas.
     */
    function doPrintPdfLayout()
    {
        $footerMm  = 22;
        $sigBandMm = 54;
        $gapMm     = 2;

        return [
            'page_margin_top_mm'    => 42,
            'page_margin_side_mm'   => 10,
            'footer_height_mm'      => $footerMm,
            'signature_band_mm'     => $sigBandMm,
            'content_gap_mm'        => $gapMm,
            'page_margin_bottom_mm' => $footerMm + $sigBandMm + $gapMm,
            'footer_h_pt'           => 62.0,
            'header_h_pt'           => 108.0,
            'sig_band_h_pt'         => round($sigBandMm * 2.835, 1),
            'margin_side_pt'        => 28.35,
            'col_gap_pt'            => 22.68,
            'box_pad_pt'            => 8.0,
            'label_col_pt'          => 62.37,
            'sig_pad_h_pt'          => 62.37,
        ];
    }
}

if (!function_exists('doPrintCanvasWrapText')) {
    function doPrintCanvasWrapText($fontMetrics, $font, $size, $text, $maxWidth)
    {
        $text  = trim((string) $text);
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line  = '';

        foreach ($words as $word) {
            $test = $line === '' ? $word : $line . ' ' . $word;
            if ($fontMetrics->get_text_width($test, $font, $size) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line    = $word;
            } else {
                $line = $test;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines ?: [''];
    }
}

if (!function_exists('doPrintCanvasDrawBorderBox')) {
    function doPrintCanvasDrawBorderBox($canvas, $x, $y, $w, $h, array $borderRgb, $lineWidth = 1.0)
    {
        $canvas->rectangle($x, $y, $w, $h, $borderRgb, $lineWidth);
    }
}

if (!function_exists('doPrintCanvasDrawSigPad')) {
    /** Light fill + subtle border — matches HTML .dig-sig-pad */
    function doPrintCanvasDrawSigPad($canvas, $x, $y, $w, $h, $sigImagePath = '')
    {
        $fill   = [0.973, 0.980, 0.988];
        $border = [0.796, 0.835, 0.882];

        $canvas->filled_rectangle($x, $y, $w, $h, $fill);
        $canvas->rectangle($x, $y, $w, $h, $border, 0.75);

        if ($sigImagePath !== '' && is_file($sigImagePath)) {
            $pad = 3.0;
            $canvas->image(
                $sigImagePath,
                $x + $pad,
                $y + $pad,
                max(1.0, $w - $pad * 2),
                max(1.0, $h - $pad * 2)
            );
        }
    }
}

if (!function_exists('doPrintCanvasDrawLabelValueRow')) {
    function doPrintCanvasDrawLabelValueRow(
        $canvas, $fontMetrics, $fontN, $fontB,
        $x, $y, $w, $labelColW, $label, $value
    ) {
        $lineRgb = [0.612, 0.639, 0.686];
        $canvas->line($x, $y, $x + $w, $y, $lineRgb, 0.35);

        $labelClr = [0, 0, 0];
        $valueClr = [0.067, 0.094, 0.153];
        $canvas->text($x, $y + 4, $label, $fontN, 7.0, $labelClr);
        if ($value !== '') {
            $canvas->text($x + $labelColW, $y + 4, $value, $fontB, 7.5, $valueClr);
        }
    }
}

if (!function_exists('doPrintCanvasDrawSignatureBand')) {
    function doPrintCanvasDrawSignatureBand(
        $canvas, $fontMetrics, array $layout,
        $branchName, $receiverName, $receiverContact, $sigTs, $sigImagePath, $mapsUrl = ''
    ) {
        $pageW   = $canvas->get_width();
        $pageH   = $canvas->get_height();
        $footerH = $layout['footer_h_pt'];
        $sigH    = $layout['sig_band_h_pt'];
        $ml      = $layout['margin_side_pt'];
        $gap     = $layout['col_gap_pt'];
        $pad     = $layout['box_pad_pt'];
        $labelW  = $layout['label_col_pt'];

        $bandW   = $pageW - $ml * 2;
        $bandTop = $pageH - $footerH - $sigH;
        $innerW  = $bandW - $gap;
        $colLW   = round($innerW * 0.42, 1);
        $colRW   = round($innerW * 0.58, 1);
        $colLX   = $ml;
        $colRX   = $ml + $colLW + $gap;

        $genBarH = 16.0;
        $boxTop  = $bandTop + 5.0;
        $boxH    = $sigH - $genBarH - 10.0;

        $borderBox = [0.216, 0.255, 0.318];
        $labelClr  = [0, 0, 0];
        $valueClr  = [0.067, 0.094, 0.153];
        $genClr    = [0, 0, 0];

        $fontN = $fontMetrics->get_font('DejaVu Sans', 'normal');
        $fontB = $fontMetrics->get_font('DejaVu Sans', 'bold');

        // Band background + top rule
        $canvas->filled_rectangle($ml, $bandTop, $bandW, $sigH, [1, 1, 1]);
        $canvas->line($ml, $bandTop, $ml + $bandW, $bandTop, [0.820, 0.820, 0.820], 0.5);

        // ── Left box: Issued By ──────────────────────────────
        doPrintCanvasDrawBorderBox($canvas, $colLX, $boxTop, $colLW, $boxH, $borderBox, 1.0);

        $ly = $boxTop + $pad;
        $canvas->text($colLX + $pad, $ly, 'ISSUED BY', $fontN, 7.5, $labelClr);
        $ly += 13;

        $nameLines = doPrintCanvasWrapText(
            $fontMetrics, $fontB, 9.0, $branchName, $colLW - $pad * 2
        );
        foreach ($nameLines as $nameLine) {
            $canvas->text($colLX + $pad, $ly, strtoupper($nameLine), $fontB, 9.0, $valueClr);
            $ly += 12;
        }

        // ── Right box: Received By ───────────────────────────
        doPrintCanvasDrawBorderBox($canvas, $colRX, $boxTop, $colRW, $boxH, $borderBox, 1.0);

        $ry = $boxTop + $pad;
        $canvas->text($colRX + $pad, $ry, 'RECEIVED BY', $fontN, 7.5, $labelClr);
        $ry += 13;

        $sigPadW = $colRW - $pad * 2;
        $sigPadH = $layout['sig_pad_h_pt'];
        doPrintCanvasDrawSigPad($canvas, $colRX + $pad, $ry, $sigPadW, $sigPadH, $sigImagePath);
        $ry += $sigPadH + 5;

        $rowW = $sigPadW;
        $rowX = $colRX + $pad;
        doPrintCanvasDrawLabelValueRow(
            $canvas, $fontMetrics, $fontN, $fontB,
            $rowX, $ry, $rowW, $labelW, 'Receiver Name:', $receiverName
        );
        $ry += 13;
        doPrintCanvasDrawLabelValueRow(
            $canvas, $fontMetrics, $fontN, $fontB,
            $rowX, $ry, $rowW, $labelW, 'Receiver Number:', $receiverContact
        );
        $ry += 13;

        if ($sigTs !== '') {
            $tsLabel = 'Timestamp:';
            $canvas->text($rowX, $ry + 2, $tsLabel, $fontN, 7.0, $labelClr);
            $tsLabelW = $fontMetrics->get_text_width($tsLabel . ' ', $fontN, 7.0);
            $canvas->text($rowX + $tsLabelW, $ry + 2, $sigTs, $fontB, 7.0, $valueClr);
            $ry += 13;
        }

        // if ($mapsUrl !== '') {
        //     $locLabel = 'Location:';
        //     $canvas->text($rowX, $ry + 2, $locLabel, $fontN, 7.0, $labelClr);
        //     $locLabelW = $fontMetrics->get_text_width($locLabel . ' ', $fontN, 7.0);
        //     $locLines = doPrintCanvasWrapText(
        //         $fontMetrics, $fontN, 6.5, $mapsUrl, $rowW - $locLabelW
        //     );
        //     foreach ($locLines as $i => $locLine) {
        //         $canvas->text($rowX + ($i === 0 ? $locLabelW : 0), $ry + 2, $locLine, $fontN, 6.5, [0.114, 0.306, 0.847]);
        //         $ry += 10;
        //     }
        // }

        // ── Generated disclaimer (centred) ───────────────────
        $genText = 'This is a computer-generated document.';
        $genW    = $fontMetrics->get_text_width($genText, $fontN, 7.0);
        $genY    = $bandTop + $sigH - 12;
        $canvas->line($ml, $genY - 2, $ml + $bandW, $genY - 2, [0.820, 0.835, 0.859], 0.35);
        $canvas->text($ml + ($bandW - $genW) / 2, $genY + 1, $genText, $fontN, 7.0, $genClr);
    }
}

if (!function_exists('doPrintPdfPageMarginBottomMm')) {
    function doPrintPdfPageMarginBottomMm()
    {
        return doPrintPdfLayout()['page_margin_bottom_mm'];
    }
}

if (!function_exists('doPrintPdfCss')) {
    function doPrintPdfCss()
    {
        $layout     = doPrintPdfLayout();
        $marginTop  = $layout['page_margin_top_mm'];
        $marginSide = $layout['page_margin_side_mm'];
        $marginBot  = $layout['page_margin_bottom_mm'];

        return '
        @page {
            size: A4 portrait;
            margin: ' . $marginTop . 'mm ' . $marginSide . 'mm ' . $marginBot . 'mm ' . $marginSide . 'mm !important;
        }
        @media print {
            @page {
                size: A4 portrait;
                margin: ' . $marginTop . 'mm ' . $marginSide . 'mm ' . $marginBot . 'mm ' . $marginSide . 'mm !important;
            }
        }
        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            font-family: DejaVu Sans, Arial, sans-serif;
        }
        .a4-paper {
            width: 100%;
            max-width: none;
            margin: 0;
            box-shadow: none;
            position: static;
        }
        .doc-content {
            padding: 0;
        }
        .do-pdf-header,
        .do-pdf-footer,
        .letterhead-area,
        .footer-area,
        .do-pdf-signature-band,
        .print-closing-block {
            display: none !important;
        }
        .info-grid-table {
            table-layout: fixed;
            width: 100%;
            border-collapse: separate;
            border-spacing: 4mm 0;
        }
        .info-box-cell {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }
        .info-box {
            border: 1.2px solid #374151;
            border-radius: 12px;
            -webkit-border-radius: 12px;
            overflow: hidden;
            height: 38mm;
            min-height: 38mm;
            box-sizing: border-box;
        }
        .print-table-zone,
        .items-table {
            width: 100%;
            table-layout: fixed;
        }
        .items-table thead th:nth-child(1),
        .items-table tbody td:nth-child(1) {
            width: 10%;
            text-align: center;
        }
        .items-table thead th:nth-child(2),
        .items-table tbody td:nth-child(2) {
            width: 66%;
        }
        .items-table thead th:nth-child(3),
        .items-table tbody td:nth-child(3) {
            width: 12%;
            text-align: center;
        }
        .items-table thead th:nth-child(4),
        .items-table tbody td:nth-child(4) {
            width: 12%;
            text-align: center;
        }
        .print-table-zone {
            margin-bottom: 0;
        }
        .items-table {
            margin-bottom: 0;
        }
        .items-table thead {
            display: table-header-group;
        }
        .items-table tfoot {
            display: none !important;
        }
        .items-table tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .page-continue-row {
            display: none !important;
        }
        ';
    }
}

if (!function_exists('doPrintGeneratePdfBinary')) {
    function doPrintGeneratePdfBinary(array $data)
    {
        doPrintEnsureVendorAutoload();

        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new RuntimeException(
                'PDF library (Dompdf) is not installed on this server. Deploy the vendor folder or run composer install.'
            );
        }

        $moduleDir = realpath(__DIR__ . '/../modules/do_dispatch');
        if ($moduleDir === false) {
            throw new RuntimeException('DO print module path not found.');
        }

        // --- Extract signature data for canvas drawing ---
        $do_header      = $data['do_header'];
        $branch_info    = $data['branch_info'] ?? [];
        $branchName     = !empty($branch_info['branch_name'])
                          ? $branch_info['branch_name']
                          : ($_SESSION['branch_name'] ?? '');
        $receiverName    = trim($do_header['receiver_name']    ?? '');
        $receiverContact = trim($do_header['receiver_contact'] ?? '');
        $sigTs           = !empty($do_header['signature_date'])
                          ? date('M d, Y - H:i', strtotime($do_header['signature_date']))
                          : '';
        $mapsUrl         = doPrintGoogleMapsUrl(
            $do_header['receiver_latitude'] ?? null,
            $do_header['receiver_longitude'] ?? null
        );

        $sigImagePath = '';
        $sigPathDb    = trim($do_header['receiver_signature_path'] ?? '');
        if ($sigPathDb !== '') {
            $sigFile = $moduleDir . DIRECTORY_SEPARATOR . 'uploads'
                     . DIRECTORY_SEPARATOR . 'signatures'
                     . DIRECTORY_SEPARATOR . basename($sigPathDb);
            if (is_file($sigFile)) {
                $sigImagePath = $sigFile;
            }
        }

        // Use exact header/footer paths from branch_master for PDF.
        $headerPath = doPrintNormalizeFsPath($branch_info['header_filepath'] ?? '');
        $footerPath = doPrintNormalizeFsPath($branch_info['footer_filepath'] ?? '');
        if ($headerPath === '' || !@is_file($headerPath)) {
            $headerPath = $moduleDir . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . 'header.jpeg';
        }
        if ($footerPath === '' || !@is_file($footerPath)) {
            $footerPath = $moduleDir . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . 'footer.jpeg';
        }

        // --- Build PDF HTML (signature band drawn on canvas, not in HTML) ---
        $body = doPrintRenderBodyHtml($data, ['for_pdf' => true]);
        $body = preg_replace('/<tfoot\b[^>]*>.*?<\/tfoot>/is', '', $body);
        // Normalize items-table header markup so CSS column widths apply in Dompdf
        $body = preg_replace('/(<th\b[^>]*)\sstyle="[^"]*"/i', '$1', $body);
        $body = str_replace(
            [
                '<th class="center">No.</th>',
                '<th>Description</th>',
                '<th class="center">Qty</th>',
                '<th class="center">Unit</th>',
            ],
            [
                '<th class="center col-no">No.</th>',
                '<th class="col-desc">Description</th>',
                '<th class="center col-qty">Qty</th>',
                '<th class="center col-unit">Unit</th>',
            ],
            $body
        );
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
              . doPrintStylesCss(true)
              . doPrintPdfCss()
              . '</style></head><body>'
              . '<div class="a4-paper">' . $body . '</div>'
              . '</body></html>';

        $options = new \Dompdf\Options();
        $isRemote = preg_match('/^(https?:\\/\\/|\\\\\\\\)/i', (string) $headerPath)
                 || preg_match('/^(https?:\\/\\/|\\\\\\\\)/i', (string) $footerPath);
        $options->set('isRemoteEnabled', $isRemote);

        $chrootDirs = [$moduleDir, __DIR__];
        $hDir = $headerPath !== '' ? dirname($headerPath) : '';
        $fDir = $footerPath !== '' ? dirname($footerPath) : '';
        if ($hDir !== '' && !in_array($hDir, $chrootDirs, true)) {
            $chrootDirs[] = $hDir;
        }
        if ($fDir !== '' && !in_array($fDir, $chrootDirs, true)) {
            $chrootDirs[] = $fDir;
        }
        $options->set('chroot', $chrootDirs);

        try {
            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
        } catch (Throwable $e) {
            throw new RuntimeException('PDF render failed: ' . $e->getMessage(), 0, $e);
        }

        // --- Draw header, footer, and signature band on every page via page_script ---
        $layout = doPrintPdfLayout();

        $canvas = $dompdf->getCanvas();
        $canvas->page_script(function (
            $pageNumber, $pageCount, $canvas, $fontMetrics
        ) use (
            $headerPath, $footerPath, $layout,
            $branchName, $receiverName, $receiverContact, $sigTs, $sigImagePath, $mapsUrl
        ) {
            unset($pageNumber, $pageCount);

            $pageW   = $canvas->get_width();
            $pageH   = $canvas->get_height();
            $headerH = $layout['header_h_pt'];
            $footerH = $layout['footer_h_pt'];

            if (is_file($headerPath)) {
                $canvas->image($headerPath, 0, 0, $pageW, $headerH);
            }
            if (is_file($footerPath)) {
                $canvas->image($footerPath, 0, $pageH - $footerH, $pageW, $footerH);
            }

            doPrintCanvasDrawSignatureBand(
                $canvas,
                $fontMetrics,
                $layout,
                $branchName,
                $receiverName,
                $receiverContact,
                $sigTs,
                $sigImagePath,
                $mapsUrl
            );
        });

        return $dompdf->output();
    }
}

if (!function_exists('doPrintIsReceiverProofComplete')) {
    /**
     * True when signature path, receiver name, and receiver contact are all captured.
     */
    function doPrintIsReceiverProofComplete(array $do_header, $verifySignatureFile = false)
    {
        $sigPath = trim((string) ($do_header['receiver_signature_path'] ?? ''));
        $name    = trim((string) ($do_header['receiver_name'] ?? ''));
        $contact = trim((string) ($do_header['receiver_contact'] ?? ''));

        if ($sigPath === '' || $name === '' || $contact === '') {
            return false;
        }

        if (!$verifySignatureFile) {
            return true;
        }

        $moduleDir = realpath(__DIR__ . '/../modules/do_dispatch');
        if ($moduleDir === false) {
            return false;
        }

        $sigFile = $moduleDir . DIRECTORY_SEPARATOR . 'uploads'
                 . DIRECTORY_SEPARATOR . 'signatures'
                 . DIRECTORY_SEPARATOR . basename($sigPath);

        return is_file($sigFile);
    }
}

if (!function_exists('doPrintValidateReceiverProofOfDelivery')) {
    /**
     * Block dispatch/email until proof-of-delivery fields are saved on delivery_note_h.
     */
    function doPrintValidateReceiverProofOfDelivery(array $do_header)
    {
        $sigPath = trim((string) ($do_header['receiver_signature_path'] ?? ''));
        $name    = trim((string) ($do_header['receiver_name'] ?? ''));
        $contact = trim((string) ($do_header['receiver_contact'] ?? ''));

        $missing = [];
        if ($name === '') {
            $missing[] = 'receiver name';
        }
        if ($contact === '') {
            $missing[] = 'receiver number';
        }
        if ($sigPath === '') {
            $missing[] = 'receiver signature';
        } else {
            $moduleDir = realpath(__DIR__ . '/../modules/do_dispatch');
            if ($moduleDir !== false) {
                $sigFile = $moduleDir . DIRECTORY_SEPARATOR . 'uploads'
                         . DIRECTORY_SEPARATOR . 'signatures'
                         . DIRECTORY_SEPARATOR . basename($sigPath);
                if (!is_file($sigFile)) {
                    $missing[] = 'receiver signature file';
                }
            }
        }

        if (!empty($missing)) {
            throw new RuntimeException(
                'Cannot dispatch this delivery order. Please capture '
                . implode(', ', $missing)
                . ' before sending.'
            );
        }
    }
}

if (!function_exists('doPrintValidateDispatchAccess')) {
    function doPrintValidateDispatchAccess(PDO $con, $company_code, $branch_code, $user, $doc_no)
    {
        $data = doPrintLoadData($con, $company_code, $branch_code, $doc_no);

        if (strtoupper(trim((string) ($data['do_header']['despatch'] ?? ''))) !== 'N') {
            throw new RuntimeException('This delivery order is not pending dispatch.');
        }
        $assigned = trim((string) ($data['do_header']['despatch_to'] ?? ''));
        $user     = trim((string) $user);
        if (strcasecmp($assigned, $user) !== 0) {
            throw new RuntimeException('You are not assigned to dispatch this delivery order.');
        }

        doPrintValidateReceiverProofOfDelivery($data['do_header']);

        doPrintDispatchMailContextSet($doc_no, $data);

        return $data;
    }
}

if (!function_exists('doPrintMarkDispatched')) {
    function doPrintMarkDispatched(PDO $con, $company_code, $branch_code, $user, $doc_no)
    {
        $despatch_date = date('Y-m-d H:i:s');
        $user          = trim((string) $user);

        $sql = "UPDATE delivery_note_h
                SET    despatch      = 'Y',
                       despatch_date = :despatch_date
                WHERE  doc_no       = :doc_no
                  AND  company_code = :company_code
                  AND  branch_code  = :branch_code
                  AND  despatch     = 'N'
                  AND  despatch_to  = :user_id";

        $stmt = $con->prepare($sql);
        $stmt->bindValue(':despatch_date', $despatch_date);
        $stmt->bindValue(':doc_no',       $doc_no);
        $stmt->bindValue(':company_code', $company_code);
        $stmt->bindValue(':branch_code',  $branch_code);
        $stmt->bindValue(':user_id',      $user);
        $stmt->execute();

        $check = $con->prepare(
            "SELECT despatch
             FROM   delivery_note_h
             WHERE  doc_no       = :doc_no
               AND  company_code = :company_code
               AND  branch_code  = :branch_code"
        );
        $check->execute([
            ':doc_no'       => $doc_no,
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
        ]);
        if (strtoupper(trim((string) $check->fetchColumn())) !== 'Y') {
            throw new RuntimeException('Delivery order dispatch status was not updated.');
        }

        return $despatch_date;
    }
}

if (!function_exists('doPrintResolveStoragePaths')) {
    function doPrintResolveStoragePaths(PDO $con, $company_code, $branch_code)
    {
        $sql = "SELECT TOP 1 doc_filepath
                FROM   doc_master
                WHERE  company_code = :company_code
                  AND  branch_code  = :branch_code
                  AND  doc_code     = 'DO'
                  AND  doc_type     = 'DLO'";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
        ]);
        $path = trim((string) $stmt->fetchColumn());

        if ($path === '') {
            throw new RuntimeException('Document storage path is not configured in doc_master for DO / DLO.');
        }

        return [
            'file_path' => $path,
            'dir'       => rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR),
        ];
    }
}

if (!function_exists('doPrintResolveStorageDir')) {
    function doPrintResolveStorageDir(PDO $con, $company_code, $branch_code)
    {
        return doPrintResolveStoragePaths($con, $company_code, $branch_code)['dir'];
    }
}

if (!function_exists('doPrintEnsureStorageDir')) {
    function doPrintEnsureStorageDir($dir)
    {
        if ($dir === '') {
            throw new RuntimeException('Document storage path is empty.');
        }

        if (is_dir($dir)) {
            if (!is_writable($dir)) {
                throw new RuntimeException(
                    'Document folder exists but is not writable by the IIS app pool: ' . $dir
                    . '. Grant Modify on the folder/share for the site application pool identity.'
                );
            }
            return;
        }

        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException(
                'Could not create or reach document folder: ' . $dir
                . '. Check UNC path, share permissions, and network access from the web server.'
            );
        }
        if (!is_writable($dir)) {
            throw new RuntimeException(
                'Document folder is not writable by the IIS app pool: ' . $dir
                . '. Grant Modify on the folder/share for the site application pool identity.'
            );
        }
    }
}

if (!function_exists('doPrintBuildPdfFilename')) {
    function doPrintBuildPdfFilename($doc_no)
    {
        $safeDoc = preg_replace('/[^A-Za-z0-9\-_]+/', '_', (string) $doc_no);
        $safeDoc = trim($safeDoc, '_');
        if ($safeDoc === '') {
            $safeDoc = 'DLO';
        }

        return $safeDoc . '_' . date('Ymd_His') . '.pdf';
    }
}

if (!function_exists('doPrintSavePdf')) {
    function doPrintSavePdf($dir, $doc_no, $pdfBinary)
    {
        doPrintEnsureStorageDir($dir);
        $filename = doPrintBuildPdfFilename($doc_no);
        $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($fullPath, $pdfBinary) === false) {
            throw new RuntimeException('Could not save PDF to: ' . $fullPath);
        }

        return [
            'file_name' => $filename,
            'full_path' => $fullPath,
        ];
    }
}

if (!function_exists('doPrintGenerateAttachmentFileId')) {
    function doPrintGenerateAttachmentFileId(PDO $con)
    {
        $sql = "SELECT ISNULL(MAX(TRY_CAST(file_id AS BIGINT)), 10000000) AS max_id
                FROM   doc_attachment";

        $stmt = $con->query($sql);
        $maxId = (int) $stmt->fetchColumn();
        $next  = $maxId + 1;

        if ($next > 99999999) {
            throw new RuntimeException('Unable to generate a unique attachment file ID.');
        }

        return str_pad((string) $next, 8, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('doPrintNextAttachmentSerialNo')) {
    function doPrintNextAttachmentSerialNo(PDO $con, $company_code, $branch_code, $doc_no, $doc_type)
    {
        $sql = "SELECT ISNULL(MAX(CAST(serial_no AS INT)), 0) AS max_serial
                FROM   doc_attachment
                WHERE  company_code = :company_code
                  AND  branch_code  = :branch_code
                  AND  doc_no       = :doc_no
                  AND  doc_type     = :doc_type";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
            ':doc_no'       => $doc_no,
            ':doc_type'     => $doc_type,
        ]);

        $next = ((int) $stmt->fetchColumn()) + 1;
        if ($next > 99) {
            throw new RuntimeException('Maximum attachment serial number reached for this delivery order.');
        }

        return str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('doPrintFindDocAttachmentByFile')) {
    function doPrintFindDocAttachmentByFile(
        PDO $con,
        $company_code,
        $branch_code,
        $doc_no,
        $doc_type,
        $file_name
    ) {
        $sql = "SELECT TOP 1 serial_no, file_id, file_name, file_path
                FROM   doc_attachment
                WHERE  company_code = :company_code
                  AND  branch_code  = :branch_code
                  AND  doc_no       = :doc_no
                  AND  doc_type     = :doc_type
                  AND  file_name    = :file_name";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
            ':doc_no'       => $doc_no,
            ':doc_type'     => $doc_type,
            ':file_name'    => $file_name,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('doPrintInsertDocAttachment')) {
    /**
     * Register a saved DO PDF in doc_attachment for ERP attachment listing.
     *
     * @return array{inserted:bool,duplicate:bool,serial_no:string,file_id:string,file_name:string,file_path:string}
     */
    function doPrintInsertDocAttachment(
        PDO $con,
        $company_code,
        $branch_code,
        $doc_no,
        $doc_type,
        $folder_path,
        $file_name,
        $user_id
    ) {
        $doc_no      = trim((string) $doc_no);
        $doc_type    = trim((string) $doc_type) ?: 'DLO';
        $file_name   = trim((string) $file_name);
        $folder_path = trim((string) $folder_path);
        $user_id     = trim((string) $user_id);

        if ($doc_no === '' || $file_name === '' || $folder_path === '') {
            throw new RuntimeException('Missing data required to register the PDF attachment.');
        }

        $existing = doPrintFindDocAttachmentByFile(
            $con,
            $company_code,
            $branch_code,
            $doc_no,
            $doc_type,
            $file_name
        );
        if ($existing) {
            return [
                'inserted'   => false,
                'duplicate'  => true,
                'serial_no'  => (string) $existing['serial_no'],
                'file_id'    => (string) $existing['file_id'],
                'file_name'  => (string) $existing['file_name'],
                'file_path'  => (string) $existing['file_path'],
            ];
        }

        $dir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folder_path), DIRECTORY_SEPARATOR);
        $physicalPath = $dir . DIRECTORY_SEPARATOR . $file_name;
        if (!is_file($physicalPath)) {
            throw new RuntimeException('PDF file was saved but could not be found for attachment registration.');
        }

        $serial_no = doPrintNextAttachmentSerialNo($con, $company_code, $branch_code, $doc_no, $doc_type);
        $file_id   = doPrintGenerateAttachmentFileId($con);
        $now       = date('Y-m-d H:i:s');
        $expiry    = date('Y-m-d');

        $sql = "INSERT INTO doc_attachment
                (company_code, branch_code, doc_no, doc_type, serial_no, expiry_date,
                 file_id, file_name, file_path, user_id, user_date)
                VALUES
                (:company_code, :branch_code, :doc_no, :doc_type, :serial_no, :expiry_date,
                 :file_id, :file_name, :file_path, :user_id, :user_date)";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code' => $company_code,
            ':branch_code'  => $branch_code,
            ':doc_no'       => $doc_no,
            ':doc_type'     => $doc_type,
            ':serial_no'    => $serial_no,
            ':expiry_date'  => $expiry,
            ':file_id'      => $file_id,
            ':file_name'    => $file_name,
            ':file_path'    => $folder_path,
            ':user_id'      => $user_id,
            ':user_date'    => $now,
        ]);

        return [
            'inserted'  => true,
            'duplicate' => false,
            'serial_no' => $serial_no,
            'file_id'   => $file_id,
            'file_name' => $file_name,
            'file_path' => $folder_path,
        ];
    }
}

if (!function_exists('doPrintFetchCustomerEmails')) {
    function doPrintFetchCustomerEmails(PDO $con, $company_code, $ac_code)
    {
        $ac_code = trim((string) $ac_code);
        if ($ac_code === '') {
            throw new RuntimeException('Customer account code is missing on this delivery order.');
        }

        $sql = "SELECT contact_code,
                       contact_name,
                       contact_email
                FROM   ac_contact
                WHERE  ac_code      = :ac_code
                  AND  company_code = :company_code
                  AND  contact_email IS NOT NULL
                  AND  LTRIM(RTRIM(contact_email)) <> ''
                ORDER BY contact_code";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':ac_code'      => $ac_code,
            ':company_code' => $company_code,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $contacts = [];
        $seen     = [];
        foreach ($rows as $row) {
            $email = trim((string) ($row['contact_email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $contacts[] = [
                'contact_code' => trim((string) ($row['contact_code'] ?? '')),
                'contact_name' => trim((string) ($row['contact_name'] ?? '')),
                'email'        => $email,
            ];
        }

        return $contacts;
    }
}

if (!function_exists('doPrintNextContactCode')) {
    /**
     * Next ac_contact.contact_code for company+account (001, 002, ...).
     */
    function doPrintNextContactCode(PDO $con, $company_code, $ac_code)
    {
        $sql = "SELECT MAX(TRY_CAST(LTRIM(RTRIM(contact_code)) AS INT)) AS max_code
                FROM   ac_contact
                WHERE  company_code = :company_code
                  AND  ac_code      = :ac_code";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code' => $company_code,
            ':ac_code'      => $ac_code,
        ]);
        $max = (int) ($stmt->fetchColumn() ?: 0);
        $next = $max + 1;
        if ($next < 1) {
            $next = 1;
        }

        return str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('doPrintAddCustomerEmail')) {
    /**
     * Insert a customer email into ac_contact and return the new contact row.
     */
    function doPrintAddCustomerEmail(PDO $con, $company_code, $ac_code, $email)
    {
        $ac_code = trim((string) $ac_code);
        $email   = trim((string) $email);

        if ($ac_code === '') {
            throw new RuntimeException('Customer account code is missing on this delivery order.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $existing = doPrintFetchCustomerEmails($con, $company_code, $ac_code);
        foreach ($existing as $row) {
            if (strcasecmp($row['email'], $email) === 0) {
                throw new RuntimeException('This email is already registered for the customer.');
            }
        }

        $contact_code = doPrintNextContactCode($con, $company_code, $ac_code);

        $sql = "INSERT INTO ac_contact (company_code, ac_code, contact_code, contact_email)
                VALUES (:company_code, :ac_code, :contact_code, :contact_email)";
        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code'  => $company_code,
            ':ac_code'       => $ac_code,
            ':contact_code'  => $contact_code,
            ':contact_email' => $email,
        ]);

        return [
            'contact_code' => $contact_code,
            'contact_name' => '',
            'email'        => $email,
        ];
    }
}

if (!function_exists('doPrintFetchCustomerEmail')) {
    function doPrintFetchCustomerEmail(PDO $con, $company_code, $branch_code, $ac_code)
    {
        unset($branch_code);
        $contacts = doPrintFetchCustomerEmails($con, $company_code, $ac_code);
        if (empty($contacts)) {
            throw new RuntimeException('No valid customer email found in ac_contact for account ' . $ac_code . '.');
        }
        return $contacts[0]['email'];
    }
}

if (!function_exists('doMailResolveLogoPath')) {
    function doMailResolveLogoPath()
    {
        $moduleDir = realpath(__DIR__ . '/../modules/do_dispatch');
        if ($moduleDir === false) {
            return '';
        }

        $candidates = [
            $moduleDir . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . 'mail_logo.jpeg',
            $moduleDir . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . 'header.jpeg',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }
}

if (!function_exists('doMailEmbedImageSrc')) {
    function doMailEmbedImageSrc($filePath)
    {
        $bytes = doMailReadLogoBytes($filePath);
        if ($bytes === '') {
            return '';
        }

        $ext  = strtolower(pathinfo((string) $filePath, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        } elseif ($ext === 'gif') {
            $mime = 'image/gif';
        } elseif ($ext === 'webp') {
            $mime = 'image/webp';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}

if (!function_exists('doMailReadLogoBytes')) {
    function doMailReadLogoBytes($filePath)
    {
        if ($filePath === '' || !is_file($filePath)) {
            return '';
        }

        $bytes = @file_get_contents($filePath);

        return ($bytes === false) ? '' : (string) $bytes;
    }
}

if (!function_exists('doMailLogoContentId')) {
    function doMailLogoContentId()
    {
        return 'fandoes-mail-logo';
    }
}

if (!function_exists('doMailBuildLogoAttachmentData')) {
    /**
     * @return array{name:string,mime:string,data:string,path:string}|null
     */
    function doMailBuildLogoAttachmentData()
    {
        $logoPath = doMailResolveLogoPath();
        if ($logoPath === '') {
            return null;
        }

        $bytes = doMailReadLogoBytes($logoPath);
        if ($bytes === '') {
            return null;
        }

        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        } elseif ($ext === 'gif') {
            $mime = 'image/gif';
        } elseif ($ext === 'webp') {
            $mime = 'image/webp';
        }

        return [
            'name' => basename($logoPath),
            'mime' => $mime,
            'data' => $bytes,
            'path' => $logoPath,
        ];
    }
}

if (!function_exists('doMailBuildLogoInlineAttachment')) {
    function doMailBuildLogoInlineAttachment($contentId = null)
    {
        $logo = doMailBuildLogoAttachmentData();
        if ($logo === null) {
            return null;
        }

        $contentId = trim((string) ($contentId ?? doMailLogoContentId()));
        if ($contentId === '') {
            $contentId = doMailLogoContentId();
        }

        return [
            'name'       => $logo['name'],
            'mime'       => $logo['mime'],
            'data'       => $logo['data'],
            'inline'     => true,
            'content_id' => $contentId,
        ];
    }
}

if (!function_exists('doMailBuildLogoFileAttachment')) {
    function doMailBuildLogoFileAttachment()
    {
        $logo = doMailBuildLogoAttachmentData();
        if ($logo === null) {
            return null;
        }

        return [
            'name' => $logo['name'],
            'mime' => $logo['mime'],
            'data' => $logo['data'],
        ];
    }
}

if (!function_exists('doMailBranchSignatureProfile')) {
    /**
     * Branch-specific DO mail signature (logo + footer text).
     * branch_code 01 = Franz (logo + existing footer)
     * branch_code 03 = Novus (no logo + Novus address)
     *
     * @return array{
     *   show_logo:bool,
     *   title:string,
     *   company:string,
     *   html_footer:string,
     *   text_footer:string
     * }
     */
    function doMailBranchSignatureProfile($branchCode = null)
    {
        $branchCode = trim((string) ($branchCode ?? ($_SESSION['branch_code'] ?? '')));

        // Novus Star — branch 03: no mail logo, Novus address block
        if ($branchCode === '03') {
            $htmlFooter = '<tr><td style="padding:8px 0 6px 0;font-family:Georgia,Times New Roman,serif;font-size:11pt;color:#1d4ed8;">Thanks &amp; Regards,</td></tr>'
                . '<tr><td style="padding:0 0 2px 0;font-size:11pt;font-weight:700;color:#111827;">Store In-Charge</td></tr>'
                . '<tr><td style="padding:0 0 12px 0;font-size:11pt;font-weight:700;color:#111827;">NOVUS STAR MANUFACTURING LLC</td></tr>'
                . '<tr><td style="padding:0 0 10px 0;font-family:Calibri,Arial,sans-serif;font-size:10.5pt;color:#1d4ed8;line-height:1.65;">'
                . 'Tel: +971 4 241 8844 | Mob: +971 50 214 0860<br>'
                . 'Email: <a href="mailto:store@fandoes.com" style="color:#1d4ed8;text-decoration:underline;">store@fandoes.com</a>'
                . ' | Web: <a href="https://www.fandoes.com" style="color:#1d4ed8;text-decoration:underline;">www.fandoes.com</a><br>'
                . 'Address: Saih Shuaib 4, Dubai Industrial City | P.O. Box: 232085, Dubai, U.A.E.<br>'
                . 'Services: Laser Cutting | Waterjet Cutting | CNC Machining | Fabrication'
                . '</td></tr>'
                . '<tr><td style="padding:8px 0 0 0;font-family:Calibri,Arial,sans-serif;font-size:9pt;color:#374151;">'
                . 'This is an automated message from Fandoes'
                . '</td></tr>';

            $textFooter = "Thanks & Regards,\n"
                . "Store In-Charge\n"
                . "NOVUS STAR MANUFACTURING LLC\n"
                . "Tel: +971 4 241 8844 | Mob: +971 50 214 0860\n"
                . "Email: store@fandoes.com | Web: www.fandoes.com\n"
                . "Address: Saih Shuaib 4, Dubai Industrial City | P.O. Box: 232085, Dubai, U.A.E.\n"
                . "Services: Laser Cutting | Waterjet Cutting | CNC Machining | Fabrication\n\n"
                . "This is an automated message from Fandoes\n";

            return [
                'show_logo'   => false,
                'title'       => 'Store In-Charge',
                'company'     => 'NOVUS STAR MANUFACTURING LLC',
                'html_footer' => $htmlFooter,
                'text_footer' => $textFooter,
            ];
        }

        // Default / Franz & Olsen — branch 01 (and others): keep existing logo + address
        $htmlFooter = '<tr><td style="padding:8px 0 6px 0;font-family:Georgia,Times New Roman,serif;font-size:11pt;color:#1d4ed8;">Thanks &amp; Regards</td></tr>'
            . '<tr><td style="padding:0 0 14px 0;font-size:11pt;font-weight:700;color:#111827;">Store Incharge</td></tr>'
            . '{{LOGO_ROW}}'
            . '<tr><td style="padding:0 0 10px 0;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:auto;font-family:Calibri,Arial,sans-serif;font-size:10.5pt;color:#1d4ed8;line-height:1.65;">'
            . '<tr>'
            . '<td valign="top" width="1" style="padding:0 14px 0 0;color:#1d4ed8;white-space:nowrap;">Tel&nbsp;&nbsp;&nbsp;&nbsp;:&nbsp;&nbsp;+971&nbsp;424&nbsp;188&nbsp;844</td>'
            . '<td valign="top" style="padding:0;color:#1d4ed8;white-space:nowrap;">Dubai&nbsp;Industrial&nbsp;City</td>'
            . '</tr>'
            . '<tr>'
            . '<td valign="top" width="1" style="padding:0 14px 0 0;color:#1d4ed8;white-space:nowrap;">Mob&nbsp;:&nbsp;&nbsp;+971&nbsp;502&nbsp;140&nbsp;860</td>'
            . '<td valign="top" style="padding:0;color:#1d4ed8;white-space:nowrap;">Saih&nbsp;Shuaib&nbsp;4</td>'
            . '</tr>'
            . '<tr>'
            . '<td valign="top" width="1" style="padding:0 14px 0 0;color:#1d4ed8;white-space:nowrap;">Email&nbsp;:&nbsp;&nbsp;<a href="mailto:store@fandoes.com" style="color:#1d4ed8;text-decoration:underline;">store@fandoes.com</a></td>'
            . '<td valign="top" style="padding:0;color:#1d4ed8;white-space:nowrap;">P.O.&nbsp;BOX&nbsp;:&nbsp;232085</td>'
            . '</tr>'
            . '<tr>'
            . '<td valign="top" width="1" style="padding:0 14px 0 0;color:#1d4ed8;white-space:nowrap;">Web&nbsp;&nbsp;&nbsp;:&nbsp;&nbsp;&nbsp;<a href="https://www.fandoes.com" style="color:#1d4ed8;text-decoration:underline;">www.fandoes.com</a></td>'
            . '<td valign="top" style="padding:0;color:#1d4ed8;white-space:nowrap;">DUBAI&nbsp;U.A.E</td>'
            . '</tr>'
            . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:10px 0 0 0;font-family:Calibri,Arial,sans-serif;font-size:9.5pt;font-weight:600;color:#0f766e;letter-spacing:0.04em;">'
            . 'LASER CUTTING&nbsp;|&nbsp;WATERJET CUTTING&nbsp;|&nbsp;CNC MACHINING&nbsp;|&nbsp;FABRICATION'
            . '</td></tr>'
            . '<tr><td style="padding:8px 0 0 0;font-family:Calibri,Arial,sans-serif;font-size:9pt;color:#374151;">'
            . 'This is an automated message from Fandoes'
            . '</td></tr>';

        $textFooter = "Thanks & Regards\n"
            . "Store Incharge\n\n"
            . "Tel    : +971 424 188 844\tDubai Industrial City\n"
            . "Mob : +971 502 140 860\tSaih Shuaib 4\n"
            . "Email : store@fandoes.com\n"
            . "P.O. BOX : 232085\n"
            . "Web   : www.fandoes.com\n"
            . "DUBAI U.A.E\n\n"
            . "LASER CUTTING | WATERJET CUTTING | CNC MACHINING | FABRICATION\n\n"
            . "This is an automated message from Fandoes\n";

        return [
            'show_logo'   => true,
            'title'       => 'Store Incharge',
            'company'     => 'Franz & Olsen Engineering Services LLC',
            'html_footer' => $htmlFooter,
            'text_footer' => $textFooter,
        ];
    }
}

if (!function_exists('doMailBuildDispatchEmail')) {
    /**
     * Build DO dispatch customer email bodies (HTML + plain text).
     *
     * @param array{doc_no:string,doc_date:string,customer:string,po_no:string,maps_url?:string,branch_code?:string} $vars
     * @return array{html:string,text:string,inline_logo:?array}
     */
    function doMailBuildDispatchEmail(array $vars)
    {
        $docNo    = trim((string) ($vars['doc_no'] ?? ''));
        $docDate  = trim((string) ($vars['doc_date'] ?? ''));
        $customer = trim((string) ($vars['customer'] ?? ''));
        $poNo     = trim((string) ($vars['po_no'] ?? ''));
        $mapsUrl  = trim((string) ($vars['maps_url'] ?? ''));
        $branchCode = trim((string) ($vars['branch_code'] ?? ($_SESSION['branch_code'] ?? '')));
        if ($poNo === '') {
            $poNo = '—';
        }

        $esc = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        $profile    = doMailBranchSignatureProfile($branchCode);
        $logoCid    = doMailLogoContentId();
        $inlineLogo = null;
        $logoHtml   = '';

        if (!empty($profile['show_logo'])) {
            $inlineLogo = doMailBuildLogoInlineAttachment($logoCid);
            $logoHtml   = $inlineLogo
                ? '<img src="cid:' . $logoCid . '" alt="' . $esc($profile['company']) . '" width="320" style="display:block;border:0;max-width:320px;height:auto;margin:0 0 14px 0;">'
                : '';
        }

        $htmlFooter = (string) ($profile['html_footer'] ?? '');
        if (strpos($htmlFooter, '{{LOGO_ROW}}') !== false) {
            $logoRow = $logoHtml !== ''
                ? '<tr><td style="padding:0;">' . $logoHtml . '</td></tr>'
                : '';
            $htmlFooter = str_replace('{{LOGO_ROW}}', $logoRow, $htmlFooter);
        }

        $html = '<!DOCTYPE html>'
            . '<html><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:0;background:#ffffff;font-family:Calibri,Arial,sans-serif;color:#000000;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;max-width:640px;">'
            . '<tr><td style="padding:0 0 16px 0;font-size:11pt;line-height:1.6;color:#000000;">'
            . '<p style="margin:0 0 12px 0;">Dear Customer,</p>'
            . '<p style="margin:0 0 12px 0;">Please find attached the signed Delivery Order '
            . '<strong>' . $esc($docNo) . '</strong>, dated '
            . '<strong>' . $esc($docDate) . '</strong>, for the following:</p>'
            . '<p style="margin:0 0 4px 0;">Customer:&nbsp;&nbsp;<strong>' . $esc($customer) . '</strong></p>'
            . '<p style="margin:0 0 16px 0;">PO No.:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<strong>' . $esc($poNo) . '</strong></p>'
            . ($mapsUrl !== ''
                ? '<p style="margin:0 0 4px 0;font-weight:700;">Receiver Location:</p>'
                  . '<p style="margin:0 0 16px 0;">View on Google Maps: '
                  . '<a href="' . $esc($mapsUrl) . '" style="color:#1d4ed8;text-decoration:underline;">'
                  . $esc($mapsUrl) . '</a></p>'
                : '')
            . '<p style="margin:0 0 12px 0;">This Delivery Order was electronically signed upon delivery and is provided for your records.</p>'
            . '<p style="margin:0 0 20px 0;">Should you have any questions regarding this delivery, please contact our Delivery Department using the details below.</p>'
            . '</td></tr>'
            . $htmlFooter
            . '</table>'
            . '</body></html>';

        $text = "Dear Customer,\n\n"
            . "Please find attached the signed Delivery Order {$docNo}, dated {$docDate}, for the following:\n\n"
            . "Customer: {$customer}\n"
            . "PO No.: {$poNo}\n\n"
            . ($mapsUrl !== ''
                ? "Receiver Location:\nView on Google Maps: {$mapsUrl}\n\n"
                : '')
            . "This Delivery Order was electronically signed upon delivery and is provided for your records.\n\n"
            . "Should you have any questions regarding this delivery, please contact our Delivery Department using the details below.\n\n"
            . (string) ($profile['text_footer'] ?? '');

        return [
            'html'        => $html,
            'text'        => $text,
            'inline_logo' => $inlineLogo,
        ];
    }
}

if (!function_exists('doPrintDispatchMailContextSet')) {
    function doPrintDispatchMailContextSet($docNo, array $orderData)
    {
        $header   = $orderData['do_header'] ?? [];
        $customer = trim((string) ($header['customer_name'] ?? $header['ac_code'] ?? ''));

        $GLOBALS['do_dispatch_mail_ctx'] = [
            'doc_no'   => trim((string) $docNo),
            'doc_date' => !empty($header['doc_dt'])
                ? date('j F Y', strtotime($header['doc_dt']))
                : date('j F Y'),
            'customer' => $customer,
            'po_no'    => trim((string) ($header['po_no'] ?? '')),
            'maps_url' => doPrintGoogleMapsUrl(
                $header['receiver_latitude'] ?? null,
                $header['receiver_longitude'] ?? null
            ),
            'branch_code' => trim((string) ($_SESSION['branch_code'] ?? '')),
        ];
    }
}

if (!function_exists('doPrintDispatchMailContextGet')) {
    function doPrintDispatchMailContextGet()
    {
        return $GLOBALS['do_dispatch_mail_ctx'] ?? null;
    }
}

if (!function_exists('doMailSendDeliveryOrder')) {
    /**
     * Send DO dispatch email with branded template, PDF attachment, and inline logo.
     *
     * @param array $mailConfig From erp_load_do_mail_config()
     * @param array|string $emails
     * @param string $subject
     * @param array $orderData Full order payload from doPrintValidateDispatchAccess()
     * @param array $pdfAttachment ['name' => '', 'mime' => '', 'data' => binary]
     */
    function doMailSendDeliveryOrder(array $mailConfig, $emails, $subject, array $orderData, array $pdfAttachment)
    {
        $header = $orderData['do_header'] ?? [];
        $ctx    = [
            'doc_no'   => trim((string) ($header['doc_no'] ?? '')),
            'doc_date' => !empty($header['doc_dt'])
                ? date('d M Y', strtotime($header['doc_dt']))
                : date('d M Y'),
            'customer' => trim((string) ($header['customer_name'] ?? $header['ac_code'] ?? '')),
            'po_no'    => trim((string) ($header['po_no'] ?? '')),
            'maps_url' => doPrintGoogleMapsUrl(
                $header['receiver_latitude'] ?? null,
                $header['receiver_longitude'] ?? null
            ),
            'branch_code' => trim((string) ($_SESSION['branch_code'] ?? '')),
        ];
        $built = doMailBuildDispatchEmail($ctx);

        require_once __DIR__ . '/do_mail_sender.php';

        $attachments = [];
        if (!empty($built['inline_logo'])) {
            $attachments[] = $built['inline_logo'];
        }
        $attachments[] = $pdfAttachment;

        try {
            return DoMailSender::send(
                $mailConfig,
                $emails,
                $subject,
                $built['html'],
                $built['text'],
                $attachments
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Email send failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
