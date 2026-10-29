<?php
/**
 * shared/print_preview.php — common print preview for every document's toolbar PREVIEW
 *
 * Opens inside the document page (erpOpenPrintPreview() in shared/toolbar.php).
 * The print format comes from document_setup.print_file for the document's code + type:
 *   modules/<module>/print_format/<print_file>.php
 *
 * URL params:
 *   doc_code — document code (e.g. TO)
 *   doc_type — document type (e.g. STO)
 *   doc_no   — saved document number
 *   pdf=1    — download the same sheets as <doc_no>.pdf
 */
ob_start();
require __DIR__ . '/../db.php';
include __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/erp_paths.php';
require_once __DIR__ . '/../includes/erp_print.php';

$company_code = $_SESSION['company_code'];
$branch_code  = $_SESSION['branch_code'];

$doc_code = strtoupper(trim((string) ($_GET['doc_code'] ?? '')));
$doc_type = strtoupper(trim((string) ($_GET['doc_type'] ?? '')));
$doc_no   = trim((string) ($_GET['doc_no'] ?? ''));
$as_pdf   = (($_GET['pdf'] ?? '') === '1');

$print_title = $doc_code;
$print_error = '';
$sheets_html = '';

if ($doc_no === '' || !ctype_alnum($doc_code) || !ctype_alnum($doc_type)) {
    $print_error = 'Save the document before opening the preview.';
} else {
    try {
        $print_format_path = erp_print_format_path($con, $company_code, $branch_code, $doc_code, $doc_type);
        if ($print_format_path === '') {
            $print_error = 'No print format is set up for ' . $doc_code . ' / ' . $doc_type . ' in Document Setup.';
        } else {
            $company = erp_print_company($con, $company_code);
            $ob_level = ob_get_level();
            ob_start();
            include $print_format_path;
            $sheets_html = ob_get_clean();
        }
    } catch (Throwable $e) {
        // Catch Error too (e.g. undefined function on older PHP) — Exception alone leaves a blank iframe
        while (isset($ob_level) && ob_get_level() > $ob_level) {
            ob_end_clean();
        }
        error_log('print_preview.php error: ' . $e->getMessage());
        $print_error = 'The preview could not be loaded.';
    }
}

if ($as_pdf) {
    if ($print_error === '') {
        try {
            erp_print_pdf($sheets_html, $doc_no);
        } catch (Throwable $e) {
            error_log('print_preview.php PDF error: ' . $e->getMessage());
            $print_error = 'The PDF could not be created.';
        }
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $print_error;
    exit;
}

$pdf_url = erp_url('shared/print_preview') . '?' . http_build_query([
    'doc_code' => $doc_code,
    'doc_type' => $doc_type,
    'doc_no'   => $doc_no,
    'pdf'      => '1',
]);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($print_title . ' ' . $doc_no); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(erp_asset('vendor/font-awesome/css/font-awesome.min.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(erp_asset_v('assets/css/erp_document.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(erp_asset_v('assets/css/erp_print.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="erp-print-page">

<div class="toolbar erp-print-bar">
    <button type="button" class="toolbar-btn" id="pvBack" title="Back to the document" onclick="erpPrintBack()">
        <i class="fa fa-arrow-left"></i><span class="tb-lbl">Back</span>
    </button>
    <div class="erp-print-name"><?php echo htmlspecialchars($print_title); ?> <span><?php echo htmlspecialchars($doc_no); ?></span></div>
    <span class="tb-fill"></span>
    <div class="tb-group">
        <button type="button" class="toolbar-btn" id="pvPrint" title="Print" onclick="window.print()"
                <?php echo $print_error !== '' ? 'disabled' : ''; ?>>
            <i class="fa fa-print"></i><span class="tb-lbl">Print</span>
        </button>
        <button type="button" class="toolbar-btn tb-primary" id="pvDownload" title="Download PDF" onclick="erpPrintDownload()"
                <?php echo $print_error !== '' ? 'disabled' : ''; ?>>
            <i class="fa fa-download"></i><span class="tb-lbl">Download</span>
        </button>
    </div>
</div>

<?php if ($print_error !== '') { ?>
<div class="erp-print-msg"><i class="fa fa-file-text-o"></i><?php echo htmlspecialchars($print_error); ?></div>
<?php } else { ?>
<div class="erp-print-pages"><?php echo $sheets_html; ?></div>
<?php } ?>

<script>
function erpPrintBack() {
    if (window.parent !== window && typeof window.parent.erpClosePrintPreview === 'function') {
        window.parent.erpClosePrintPreview();
    } else {
        window.history.back();
    }
}

function erpPrintDownload() {
    var btn = document.getElementById('pvDownload');
    btn.disabled = true;
    fetch(<?php echo json_encode($pdf_url); ?>, { credentials: 'same-origin' })
        .then(function (res) {
            var type = res.headers.get('Content-Type') || '';
            if (!res.ok || type.indexOf('application/pdf') < 0) {
                return res.text().then(function (msg) { throw new Error(msg || 'The PDF could not be created.'); });
            }
            return res.blob();
        })
        .then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = <?php echo json_encode((preg_replace('/[^A-Za-z0-9._\-]/', '_', $doc_no) ?: 'document') . '.pdf'); ?>;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        })
        .catch(function (err) { alert(err.message); })
        .then(function () { btn.disabled = false; });
}

// Narrower screens than an A4 sheet: shrink the sheets to the screen width (pages still scroll down)
function erpPrintFitWidth() {
    var pages = document.querySelector('.erp-print-pages');
    var sheet = pages ? pages.querySelector('.erp-print-sheet') : null;
    if (!sheet) return;
    pages.style.zoom = '';
    var body = getComputedStyle(document.body);
    var availW = document.documentElement.clientWidth - parseFloat(body.paddingLeft) - parseFloat(body.paddingRight);
    var scale = Math.min(1, availW / sheet.offsetWidth);
    if (scale < 1) pages.style.zoom = String(scale);
}
erpPrintFitWidth();
window.addEventListener('resize', erpPrintFitWidth);
window.addEventListener('orientationchange', erpPrintFitWidth);
window.addEventListener('load', erpPrintFitWidth);

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') erpPrintBack();
});
</script>
</body>
</html>
