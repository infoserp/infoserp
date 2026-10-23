<?php
/**
 * modules/invoice/invoice.php  —  Generic Document CONTAINER
 *
 * Entry point for any document module (Sales Invoice, Purchase Order, etc.)
 * Reads document_setup to find the correct header and detail fragments,
 * then renders the full page: page shell → shared toolbar → header → detail.
 *
 * URL params:
 *   doc_code  — document code (e.g. SI, PO, MR). Also accepted as ?code=. Defaults to 'SI' if omitted.
 *   doc_type  — document type code (e.g. CRS, CAS). Default: doc_type defined in doc_master
 *   doc_no    — existing document number to view/edit
 */
ob_start();
require('../../db.php');
include('../../auth.php');
require_once __DIR__ . '/../../includes/erp_paths.php';
require_once __DIR__ . '/../../includes/doc_setup.php';
erp_guard_standalone_get();

$company_code = $_SESSION['company_code'];
$branch_code  = $_SESSION['branch_code'];
$user         = $_SESSION['username'];

// ── Resolve doc_code from URL (or infer from folder name) ───────────────────
// This allows the same container.php to be used in any document module folder.
// Example: modules/invoice/invoice.php, modules/purchase/invoice.php, modules/material_request/invoice.php
$doc_code = 'SI'; // default
$requested_code = trim($_GET['doc_code'] ?? ($_GET['code'] ?? ''));
if ($requested_code !== '' && ctype_alnum($requested_code)) {
    $doc_code = strtoupper($requested_code);
} else {
    // Optional: infer from the containing folder name (e.g. /invoice/ → SI, /purchase/ → PO)
    $folder = basename(__DIR__);
    $doc_code_map = ['invoice' => 'SI', 'purchase' => 'PO', 'material_request' => 'MR',];
    if (isset($doc_code_map[$folder])) $doc_code = $doc_code_map[$folder];
}

// Map doc_code to header table name
// This allows the container to query the correct table for navigation and lookups
$doc_code_table_map = [
    'SI' => 'invoice_h',           // Sales Invoice
    'PO' => 'purchase_order_h',    // Purchase Order
    'MR' => 'material_request_h',  // Material Request
    'PR' => 'purchase_return_h',   // Purchase Return
];
$header_table = isset($doc_code_table_map[$doc_code]) ? $doc_code_table_map[$doc_code] : 'invoice_h';

// ── Doc types available for data entry ─────────────────────────────────────
// Defined in doc_type_master for this doc_code AND enabled in document_setup.
// Default = doc_type defined in doc_master (if enabled), else the first available.
$available_doc_types = [];
$default_doc_type = '';
try {
    $available_doc_types = array_keys(erp_doc_configured_types($con, $company_code, $branch_code, $doc_code));
    $default_doc_type = erp_doc_default_type($con, $company_code, $branch_code, $doc_code, array_flip($available_doc_types));
} catch (Exception $e) {
    error_log("invoice.php available doc_types fetch error: " . $e->getMessage());
}

// ── Resolve doc_type from URL ──────────────────────────────────────────────
// Only accept doc_types that are available for this doc_code
$show_type_selector = false; // Flag to show selection screen if no valid doc_type
$doc_type = $default_doc_type;
$requested_type = strtoupper(trim($_GET['doc_type'] ?? ($_GET['type'] ?? ''))); // ?type= is legacy
if ($requested_type !== '' && ctype_alnum($requested_type) && in_array($requested_type, $available_doc_types, true)) {
    $doc_type = $requested_type;
} elseif (empty($available_doc_types)) {
    // No doc_types configured — show selection screen
    $show_type_selector = true;
}

$doc_no = isset($_GET['doc_no']) ? trim($_GET['doc_no']) : '';

// An existing document always opens with its own saved doc_type (even one that is
// no longer enabled in document_setup), so title and PRIOR/NEXT match the record.
if ($doc_no !== '') {
    try {
        $stmt_dt_saved = $con->prepare(
            "SELECT doc_type FROM " . $header_table . " WHERE doc_no = ? AND company_code = ? AND branch_code = ?"
        );
        $stmt_dt_saved->execute([$doc_no, $company_code, $branch_code]);
        $saved_doc_type = strtoupper(trim((string) $stmt_dt_saved->fetchColumn()));
        if ($saved_doc_type !== '') {
            $doc_type = $saved_doc_type;
        }
    } catch (Exception $e) {
        error_log("invoice.php saved doc_type lookup error: " . $e->getMessage());
    }
}

// ── Look up document_setup to find correct header/detail files ────────────
$header_file = null;
$detail_file  = null;
try {
    $stmt = $con->prepare(
        "SELECT header_file, detail_file
         FROM document_setup
         WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ?"
    );
    $stmt->execute([$company_code, $branch_code, $doc_code, $doc_type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $header_file = trim($row['header_file']);   // e.g. "sales_invoice_h_01"
        $detail_file  = trim($row['detail_file']);  // e.g. "sales_invoice_d_01"
    }
} catch (Exception $e) {
    error_log("invoice.php document_setup lookup error: " . $e->getMessage());
}


// Fallback fragment files if not found in document_setup
if (!$header_file) $header_file = 'sales_invoice_h_01';
if (!$detail_file)  $detail_file  = 'sales_invoice_d_01';

// Get the document type name for the page title
$doc_type_name = $doc_type;
try {
    $doc_type_name = erp_doc_type_name($con, $company_code, $branch_code, $doc_code, $doc_type);
} catch (Exception $e) {
    error_log("invoice.php doc_type_name lookup error: " . $e->getMessage());
}

// Security: strip anything that isn't alphanumeric, underscore or hyphen
// This prevents path traversal (e.g. ../../etc/passwd)
$header_file = preg_replace('/[^a-zA-Z0-9_\-]/', '', $header_file);
$detail_file  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $detail_file);

$header_path = __DIR__ . '/header/' . $header_file . '.php';
$detail_path  = __DIR__ . '/detail/' . $detail_file  . '.php';

// ── Variables consumed by shared/toolbar.php and the fragments ────────────
$is_view_mode    = ($doc_no !== '');
$close_url       = erp_iframe_url('dashboard');
$save_label      = 'Save Invoice';
// Pass doc_code to search_document.php so it can search the right table
$search_doc_url  = erp_iframe_url('search_document?doc_code=' . urlencode($doc_code));
$selected_doc_type = $doc_type;

// Check if the logged-in user is allowed to edit documents
$user_allow_edit = 'Y'; // default open; restrict below if branch_user says otherwise
try {
    $stmt_ue = $con->prepare("SELECT allow_edit FROM branch_user WHERE user_id = ? AND branch_code = ?");
    $stmt_ue->execute([$user, $branch_code]);
    $row_ue = $stmt_ue->fetch(PDO::FETCH_ASSOC);
    if ($row_ue && isset($row_ue['allow_edit'])) {
        $user_allow_edit = strtoupper(trim($row_ue['allow_edit']));
    }
} catch (Exception $e) {
    error_log("invoice.php allow_edit fetch error: " . $e->getMessage());
}

// Prior / Next document navigation
$prev_doc_no = '';
$next_doc_no = '';
if ($doc_no !== '') {
    try {
        // Previous: highest doc_num that is less than the current
        $sql_prev = "SELECT doc_no FROM " . $header_table . "
                     WHERE company_code = ? AND branch_code = ? AND doc_type = ?
                       AND doc_num < (SELECT doc_num FROM " . $header_table . " WHERE doc_no = ? AND company_code = ? AND branch_code = ?)
                     ORDER BY doc_num DESC
                     OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY";
        $stmt_prev = $con->prepare($sql_prev);
        $stmt_prev->execute([$company_code, $branch_code, $doc_type,
                             $doc_no, $company_code, $branch_code]);
        $row_prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);
        if ($row_prev) $prev_doc_no = trim($row_prev['doc_no']);

        // Next: lowest doc_num that is greater than the current
        $sql_next = "SELECT doc_no FROM " . $header_table . "
                     WHERE company_code = ? AND branch_code = ? AND doc_type = ?
                       AND doc_num > (SELECT doc_num FROM " . $header_table . " WHERE doc_no = ? AND company_code = ? AND branch_code = ?)
                     ORDER BY doc_num ASC
                     OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY";
        $stmt_next = $con->prepare($sql_next);
        $stmt_next->execute([$company_code, $branch_code, $doc_type,
                             $doc_no, $company_code, $branch_code]);
        $row_next = $stmt_next->fetch(PDO::FETCH_ASSOC);
        if ($row_next) $next_doc_no = trim($row_next['doc_no']);
    } catch (Exception $e) {
        error_log("invoice.php prev/next nav error: " . $e->getMessage());
    }
} else {
    // New document page (no doc_no): PRIOR navigates to the most recent saved document of this type
    if ($doc_type !== '') {
        try {
            $stmt_last = $con->prepare(
                "SELECT TOP 1 doc_no FROM " . $header_table . "
                 WHERE company_code = ? AND branch_code = ? AND doc_type = ?
                 ORDER BY doc_num DESC"
            );
            $stmt_last->execute([$company_code, $branch_code, $doc_type]);
            $row_last = $stmt_last->fetch(PDO::FETCH_ASSOC);
            if ($row_last) $prev_doc_no = trim($row_last['doc_no']);
        } catch (Exception $e) {
            error_log("invoice.php last-doc nav error: " . $e->getMessage());
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($doc_type_name . ' (' . $doc_code . ')'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="../../material_request.css?v=5">
    <link rel="stylesheet" href="../../assets/css/erp_document.css?v=27">
</head>
<body>

    <?php 
    // Show doc_type selector screen if no doc_types are configured or no doc_type is provided
    if (empty($available_doc_types) || ($doc_type === '' && !isset($_GET['doc_type']) && !isset($_GET['type']))) {
        ?>
        <div class="container" style="margin-top:60px;max-width:500px;">
            <div class="card" style="border:2px solid #3b82f6;border-radius:12px;box-shadow:0 4px 6px rgba(0,0,0,.1);">
                <div class="card-body" style="padding:32px;text-align:center;">
                    <h3 style="color:#1e40af;margin-bottom:8px;font-weight:700;">
                        <i class="fa fa-file-o" style="color:#3b82f6;margin-right:8px;"></i>
                        SELECT DOCUMENT TYPE
                    </h3>
                    <p style="color:#6b7280;margin-bottom:24px;font-size:0.95rem;">
                        Choose the document type you want to create or view
                    </p>

                    <?php if (empty($available_doc_types)) { ?>
                        <div class="alert alert-warning" style="margin:16px 0;">
                            <strong>No document types configured</strong><br>
                            Please configure document types in 
                            <a href="<?php echo htmlspecialchars(erp_page_url('document_setup'), ENT_QUOTES, 'UTF-8'); ?>" style="color:#3b82f6;font-weight:600;">Document Setup</a>
                            for this company/branch.
                        </div>
                    <?php } else { ?>
                        <form method="GET" style="display:flex;flex-direction:column;gap:12px;">
                            <?php if (erp_is_embedded()) { ?>
                            <input type="hidden" name="embed" value="1">
                            <?php } ?>
                            <input type="hidden" name="doc_code" value="<?php echo htmlspecialchars($doc_code); ?>">
                            <select name="doc_type" class="form-control" style="font-size:1rem;padding:10px;border:2px solid #d1d5db;border-radius:8px;" required onchange="this.form.submit();">
                                <option value="">-- Select Type --</option>
                                <?php foreach ($available_doc_types as $type) {
                                    $type_name = '';
                                    try {
                                        $stmt_name = $con->prepare("SELECT doc_name FROM doc_type_master WHERE company_code=? AND branch_code=? AND doc_code=? AND doc_type=?");
                                        $stmt_name->execute([$company_code, $branch_code, $doc_code, $type]);
                                        $r = $stmt_name->fetch(PDO::FETCH_ASSOC);
                                        $type_name = $r ? trim($r['doc_name']) : $type;
                                    } catch (Exception $e) {
                                        $type_name = $type;
                                    }
                                    echo '<option value="' . htmlspecialchars($type) . '">' . htmlspecialchars($type_name) . ' (' . htmlspecialchars($type) . ')</option>';
                                } ?>
                            </select>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php
        // Exit here — don't show the main form yet
        ?>
        <script src="../../vendor/jquery/jquery.min.js"></script>
        <script src="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
    </body>
    </html>
        <?php
        exit;
    }
    ?>

    <?php 
    try {
        include('../../shared/toolbar.php'); 
    } catch (Exception $e) {
        echo '<div class="alert alert-danger" style="margin:16px;"><strong>Toolbar Error:</strong> ' 
            . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>

    <div class="container-fluid">
    <div class="mr-container doc-page">

        <?php
        // ── Header fragment ──────────────────────────────────────────────
        try {
            if (file_exists($header_path)) {
                include($header_path);
            } else {
                echo '<div class="alert alert-danger" style="margin:16px;">Header file not found: '
                    . htmlspecialchars($header_file) . '.php<br>Path: ' . htmlspecialchars($header_path) . '</div>';
            }
        } catch (Exception $e) {
            echo '<div class="alert alert-danger" style="margin:16px;"><strong>Header Fragment Error:</strong> ' 
                . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>

        <?php
        // ── Detail fragment ──────────────────────────────────────────────
        try {
            if (file_exists($detail_path)) {
                include($detail_path);
            } else {
                echo '<div class="alert alert-info" style="margin:16px;">Detail file not yet configured: '
                    . htmlspecialchars($detail_file) . '.php<br>Path: ' . htmlspecialchars($detail_path) . '</div>';
            }
        } catch (Exception $e) {
            echo '<div class="alert alert-danger" style="margin:16px;"><strong>Detail Fragment Error:</strong> ' 
                . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>

    </div><!-- /.mr-container -->
    </div><!-- /.container-fluid -->

    <script src="../../vendor/jquery/jquery.min.js"></script>
    <script src="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
    <script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/approvalmenu.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/erp_document.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
