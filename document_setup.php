<?php
ob_start();
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';
erp_guard_standalone_get();

$company_code = $_SESSION["company_code"];
$branch_code  = $_SESSION["branch_code"];
$user         = $_SESSION['username'];

// Fetch available doc codes from doc_type_master
$doc_codes = [];
try {
    $stmt = $con->prepare("SELECT DISTINCT doc_code FROM doc_type_master WHERE company_code = ? AND branch_code = ? ORDER BY doc_code ASC");
    $stmt->execute([$company_code, $branch_code]);
    $doc_codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Doc codes fetch error: " . $e->getMessage());
}

// Fetch available doc types
$doc_types = [];
try {
    $stmt = $con->prepare("SELECT DISTINCT doc_type FROM doc_type_master WHERE company_code = ? AND branch_code = ? ORDER BY doc_type ASC");
    $stmt->execute([$company_code, $branch_code]);
    $doc_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Doc types fetch error: " . $e->getMessage());
}

// Fetch doc_code → doc_type[] mapping for JS filtering
$doc_type_map = [];
try {
    $stmt = $con->prepare("SELECT doc_code, doc_type FROM doc_type_master WHERE company_code = ? AND branch_code = ? ORDER BY doc_code ASC, doc_type ASC");
    $stmt->execute([$company_code, $branch_code]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $doc_type_map[trim($r['doc_code'])][] = trim($r['doc_type']);
    }
} catch (Exception $e) {
    error_log("Doc type map fetch error: " . $e->getMessage());
}

// Handle Add New Row (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_row') {
    ob_end_clean();
    header('Content-Type: application/json');
    
    $new_row_id = 'new_' . time() . '_' . rand(1000, 9999);
    
    // Build dropdown options
    $doc_code_options = '';
    foreach ($doc_codes as $code) {
        $doc_code_options .= '<option value="' . htmlspecialchars($code) . '">' . htmlspecialchars($code) . '</option>';
    }
    
    $doc_type_options = '';
    foreach ($doc_types as $type) {
        $doc_type_options .= '<option value="' . htmlspecialchars($type) . '">' . htmlspecialchars($type) . '</option>';
    }
    
    $row_count = isset($_POST['row_count']) ? intval($_POST['row_count']) + 1 : 1;
    
    echo json_encode([
        'success' => true,
        'row_id' => $new_row_id,
        'row_number' => $row_count,
        'html' => '
        <tr data-row-id="' . $new_row_id . '" class="edit-row">
            <td class="row-number"><input type="checkbox" class="row-checkbox" name="selected[]" value="' . $new_row_id . '"></td>
            <td style="text-align:center;font-weight:600;color:#3b82f6;">' . $row_count . '</td>
            <td><select class="form-control form-control-sm ds-select" name="doc_code[]" required onchange="filterDocTypes(this)"><option value="">Select Code</option>' . $doc_code_options . '</select></td>
            <td><select class="form-control form-control-sm ds-select ds-type-select" name="doc_type[]" required><option value="">Select Type</option>' . $doc_type_options . '</select></td>
            <td><select class="form-control form-control-sm ds-select ds-file-select" name="header_file[]" data-kind="header" data-saved="" required><option value="">Header</option></select></td>
            <td><select class="form-control form-control-sm ds-select ds-file-select" name="detail_file[]" data-kind="detail" data-saved="" required><option value="">Detail</option></select></td>
            <td><select class="form-control form-control-sm ds-select ds-file-select" name="print_file[]" data-kind="print" data-saved="" required><option value="">Print</option></select></td>
            <td style="text-align:center;"><button type="button" class="btn btn-sm btn-action-delete" onclick="removeRow(this)"><i class="fa fa-trash"></i></button></td>
            <input type="hidden" name="row_id[]" value="' . $new_row_id . '">
        </tr>'
    ]);
    exit;
}

// Handle Save (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    ob_end_clean();
    header('Content-Type: application/json');
    
    try {
        $doc_codes = $_POST['doc_code'] ?? [];
        $doc_types = $_POST['doc_type'] ?? [];
        $header_files = $_POST['header_file'] ?? [];
        $detail_files = $_POST['detail_file'] ?? [];
        $print_files = $_POST['print_file'] ?? [];
        $row_ids = $_POST['row_id'] ?? [];
        
        $con->beginTransaction();
        
        foreach ($row_ids as $index => $row_id) {
            $row_code = trim($doc_codes[$index] ?? '');
            $row_type = trim($doc_types[$index] ?? '');
            if (!in_array($row_type, $doc_type_map[$row_code] ?? [], true)) {
                throw new Exception("Invalid: doc_type '" . $row_type . "' is not defined in Document Type Master for doc_code '" . $row_code . "'.");
            }

            if (strpos($row_id, 'new_') === 0) {
                // Check for duplicate doc_type per doc_code before inserting
                $stmt_chk = $con->prepare("SELECT COUNT(*) FROM document_setup WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ?");
                $stmt_chk->execute([$company_code, $branch_code, trim($doc_codes[$index] ?? ''), trim($doc_types[$index] ?? '')]);
                if ((int)$stmt_chk->fetchColumn() > 0) {
                    throw new Exception("Duplicate: doc_type '" . trim($doc_types[$index] ?? '') . "' already exists for doc_code '" . trim($doc_codes[$index] ?? '') . "'.");
                }
                // Insert new record
                $sql_insert = "INSERT INTO document_setup (company_code, branch_code, doc_code, doc_type, header_file, detail_file, print_file)
                              VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $con->prepare($sql_insert);
                $stmt->execute([
                    $company_code,
                    $branch_code,
                    trim($doc_codes[$index] ?? ''),
                    trim($doc_types[$index] ?? ''),
                    trim($header_files[$index] ?? ''),
                    trim($detail_files[$index] ?? ''),
                    trim($print_files[$index] ?? '')
                ]);
            } else {
                // Check for duplicate doc_type per doc_code (excluding current record)
                $stmt_chk = $con->prepare("SELECT COUNT(*) FROM document_setup WHERE company_code = ? AND branch_code = ? AND doc_code = ? AND doc_type = ? AND id <> ?");
                $stmt_chk->execute([$company_code, $branch_code, trim($doc_codes[$index] ?? ''), trim($doc_types[$index] ?? ''), $row_id]);
                if ((int)$stmt_chk->fetchColumn() > 0) {
                    throw new Exception("Duplicate: doc_type '" . trim($doc_types[$index] ?? '') . "' already exists for doc_code '" . trim($doc_codes[$index] ?? '') . "'.");
                }
                // Update existing record
                $sql_update = "UPDATE document_setup SET 
                              doc_code = ?, doc_type = ?, header_file = ?, detail_file = ?, print_file = ?
                              WHERE id = ? AND company_code = ? AND branch_code = ?";
                $stmt = $con->prepare($sql_update);
                $stmt->execute([
                    trim($doc_codes[$index] ?? ''),
                    trim($doc_types[$index] ?? ''),
                    trim($header_files[$index] ?? ''),
                    trim($detail_files[$index] ?? ''),
                    trim($print_files[$index] ?? ''),
                    $row_id,
                    $company_code,
                    $branch_code
                ]);
            }
        }
        
        $con->commit();
        echo json_encode(['success' => true, 'message' => 'Records saved successfully']);
    } catch (Exception $e) {
        $con->rollBack();
        error_log("Document setup save error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle Delete (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    ob_end_clean();
    header('Content-Type: application/json');
    
    try {
        $id = $_POST['id'] ?? null;
        if ($id && strpos($id, 'new_') !== 0) {
            $sql_delete = "DELETE FROM document_setup WHERE id = ? AND company_code = ? AND branch_code = ?";
            $stmt = $con->prepare($sql_delete);
            $stmt->execute([$id, $company_code, $branch_code]);
        }
        echo json_encode(['success' => true, 'message' => 'Record deleted successfully']);
    } catch (Exception $e) {
        error_log("Document setup delete error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch existing records
$documents = [];
try {
    $stmt = $con->prepare("SELECT id, doc_code, doc_type, header_file, detail_file, print_file 
                          FROM document_setup 
                          WHERE company_code = ? AND branch_code = ? 
                          ORDER BY doc_code ASC");
    $stmt->execute([$company_code, $branch_code]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Document setup fetch error: " . $e->getMessage());
}

// doc_code → doc_types already saved (the Type dropdown offers only the remaining ones)
$saved_type_map  = [];
// doc_code → print files already saved (offered while the module has no print_format/ files)
$saved_print_map = [];
// doc_code → module folder, found from the saved header/detail file of that code
$doc_folder_map  = [];
$module_dirs     = glob(__DIR__ . '/modules/*', GLOB_ONLYDIR) ?: [];
foreach ($documents as $doc) {
    $code = trim($doc['doc_code']);
    $saved_type_map[$code][] = trim($doc['doc_type']);
    $print = trim((string) $doc['print_file']);
    if ($print !== '' && !in_array($print, $saved_print_map[$code] ?? [], true)) {
        $saved_print_map[$code][] = $print;
    }
    if (isset($doc_folder_map[$code])) {
        continue;
    }
    foreach ($module_dirs as $dir) {
        if (is_file($dir . '/header/' . trim($doc['header_file']) . '.php')
            || is_file($dir . '/detail/' . trim($doc['detail_file']) . '.php')) {
            $doc_folder_map[$code] = $dir;
            break;
        }
    }
}

// doc_code → ['header' => [...], 'detail' => [...], 'print' => [...]] file names without .php
$doc_file_map = [];
foreach ($doc_folder_map as $code => $dir) {
    foreach (['header' => 'header', 'detail' => 'detail', 'print' => 'print_format'] as $kind => $folder) {
        $names = array_map(function ($path) {
            return basename($path, '.php');
        }, glob($dir . '/' . $folder . '/*.php') ?: []);
        sort($names);
        $doc_file_map[$code][$kind] = $names;
    }
}

// module folder name → ['header' => [...], 'detail' => [...], 'print' => [...]]
// offered for codes that have no saved row yet, so their module cannot be resolved
$module_file_map = [];
foreach ($module_dirs as $dir) {
    foreach (['header' => 'header', 'detail' => 'detail', 'print' => 'print_format'] as $kind => $folder) {
        $names = array_map(function ($path) {
            return basename($path, '.php');
        }, glob($dir . '/' . $folder . '/*.php') ?: []);
        sort($names);
        if ($names) {
            $module_file_map[basename($dir)][$kind] = $names;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: #f5f5f5;
            font-family: 'Inter', sans-serif;
            padding: 10px;
        }
        
        .ds-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .ds-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .ds-header h1 {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0;
        }
        
        .ds-header > div {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .ds-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding: 12px 15px;
            background-color: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .ds-btn {
            padding: 7px 14px;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .ds-btn-primary {
            background-color: #3b82f6;
            color: white;
        }
        
        .ds-btn-primary:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
        }
        
        .ds-btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        
        .ds-btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .ds-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .ds-table-wrapper {
            overflow-x: auto;
            padding: 15px;
        }
        
        .ds-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        
        .ds-table thead {
            background-color: #eff6ff;
            border-bottom: 2px solid #3b82f6;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .ds-table thead th {
            color: #1e40af;
            padding: 10px 8px;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }
        
        .ds-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background-color 0.2s ease;
        }
        
        .ds-table tbody tr:hover {
            background-color: #f0f9ff;
        }
        
        .ds-table tbody tr.edit-row {
            background-color: #eff6ff;
        }
        
        .ds-table tbody td {
            padding: 10px 8px;
            vertical-align: middle;
        }
        
        .ds-table input,
        .ds-table select {
            height: 28px;
            padding: 4px 6px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.75rem;
            width: 100%;
            font-family: inherit;
        }
        
        .ds-table input:focus,
        .ds-table select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }
        
        .ds-select {
            padding: 4px 4px !important;
        }

        .ds-table select.ds-type-select,
        .ds-table select[name="doc_code[]"] {
            min-width: 84px;
        }
        
        .ds-table button {
            padding: 5px 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.2s ease;
        }
        
        .btn-action-delete {
            background-color: #fee2e2;
            color: #dc2626;
            width: 100%;
        }
        
        .btn-action-delete:hover {
            background-color: #fca5a5;
            color: white;
        }
        
        .alert {
            margin: 15px 15px 0 15px;
            padding: 10px 12px;
            border-radius: 6px;
            display: none;
            font-size: 0.85rem;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }
        
        .alert.show {
            display: block;
        }
        
        .empty-state {
            text-align: center;
            padding: 30px 15px;
            color: #9ca3af;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 8px;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 0.85rem;
            margin: 0;
        }
        
        .row-number {
            text-align: center;
            font-weight: 600;
            color: #3b82f6;
            width: 30px;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .ds-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .ds-header h1 {
                font-size: 1.1rem;
            }
            
            .ds-btn {
                padding: 6px 12px;
                font-size: 0.75rem;
            }
            
            .ds-table {
                font-size: 0.75rem;
            }
            
            .ds-table input,
            .ds-table select {
                height: 26px;
                font-size: 0.7rem;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 5px;
            }
            
            .ds-container {
                border-radius: 6px;
            }
            
            .ds-header {
                padding: 12px 15px;
            }
            
            .ds-header h1 {
                font-size: 1rem;
            }
            
            .ds-header > div {
                font-size: 0.75rem;
            }
            
            .ds-buttons {
                padding: 10px 12px;
                gap: 6px;
            }
            
            .ds-btn {
                padding: 5px 10px;
                font-size: 0.7rem;
            }
            
            .ds-table-wrapper {
                padding: 10px;
            }
            
            .ds-table {
                font-size: 0.7rem;
            }
            
            .ds-table thead th,
            .ds-table tbody td {
                padding: 8px 5px;
            }
            
            .ds-table input,
            .ds-table select {
                height: 24px;
                padding: 2px 4px;
                font-size: 0.65rem;
            }
            
            .alert {
                margin: 12px 12px 0 12px;
                padding: 8px 10px;
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .ds-header h1 {
                font-size: 0.9rem;
            }
            
            .ds-btn {
                padding: 4px 8px;
                font-size: 0.65rem;
            }
            
            .ds-btn i {
                display: none;
            }
            
            .ds-table {
                font-size: 0.65rem;
            }
            
            .ds-table thead th {
                padding: 6px 4px;
            }
            
            .ds-table tbody td {
                padding: 6px 4px;
            }
            
            .ds-table input,
            .ds-table select {
                height: 22px;
                padding: 2px 3px;
                font-size: 0.6rem;
            }
        }
    </style>
</head>
<body>

<div class="ds-container">
    <!-- Header -->
    <div class="ds-header">
        <h1><i class="fa fa-file-text"></i> Document Setup (User)</h1>
        <div style="font-size: 0.9rem; opacity: 0.9;">
            Company: <strong><?php echo htmlspecialchars($company_code); ?></strong> | 
            Branch: <strong><?php echo htmlspecialchars($branch_code); ?></strong>
        </div>
    </div>
    
    <!-- Alert Messages -->
    <div id="successAlert" class="alert alert-success">
        <i class="fa fa-check-circle"></i> <span id="successMessage"></span>
    </div>
    <div id="errorAlert" class="alert alert-danger">
        <i class="fa fa-exclamation-circle"></i> <span id="errorMessage"></span>
    </div>
    
    <!-- Buttons -->
    <div class="ds-buttons">
        <button class="ds-btn ds-btn-primary" onclick="addNewRow()">
            <i class="fa fa-plus"></i> Add
        </button>
        <button class="ds-btn ds-btn-primary" onclick="saveChanges()">
            <i class="fa fa-save"></i> Save
        </button>
        <button class="ds-btn ds-btn-secondary" onclick="deleteSelected()">
            <i class="fa fa-trash"></i> Delete
        </button>
        <button class="ds-btn ds-btn-secondary" onclick="resetForm()">
            <i class="fa fa-refresh"></i> Reset
        </button>
        <button class="ds-btn ds-btn-secondary" onclick="window.location.href='<?php echo htmlspecialchars(erp_iframe_url('dashboard'), ENT_QUOTES, 'UTF-8'); ?>'">
            <i class="fa fa-times"></i> Close
        </button>
    </div>
    
    <!-- Table -->
    <div class="ds-table-wrapper">
        <form id="documentSetupForm">
            <table class="ds-table">
                <thead>
                    <tr>
                        <th style="width: 25px;"></th>
                        <th style="width: 30px;text-align:center;">#</th>
                        <th style="width: 96px;">Code</th>
                        <th style="width: 96px;">Type</th>
                        <th>Header Dw</th>
                        <th>Detail Dw</th>
                        <th>Print Dw</th>
                        <th style="width: 50px;text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="documentsTableBody">
                    <?php if (empty($documents)): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fa fa-inbox"></i>
                            <p>No documents found. Click <strong>Add</strong> to create a new entry.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php $row_num = 1; ?>
                        <?php foreach ($documents as $doc): ?>
                        <tr data-row-id="<?php echo $doc['id']; ?>" data-doc-id="<?php echo $doc['id']; ?>">
                            <td><input type="checkbox" class="row-checkbox" name="selected[]" value="<?php echo $doc['id']; ?>"></td>
                            <td class="row-number"><?php echo $row_num++; ?></td>
                            <td>
                                <select class="form-control form-control-sm ds-select" name="doc_code[]" required onchange="filterDocTypes(this)">
                                    <option value="">Select</option>
                                    <?php foreach ($doc_codes as $code): ?>
                                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo ($code === $doc['doc_code']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($code); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select class="form-control form-control-sm ds-select ds-type-select" name="doc_type[]" data-saved-type="<?php echo htmlspecialchars(trim($doc['doc_type'])); ?>" required>
                                    <option value="">Select</option>
                                    <?php foreach ($doc_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($type === $doc['doc_type']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <?php foreach (['header' => 'header_file', 'detail' => 'detail_file', 'print' => 'print_file'] as $kind => $col): ?>
                            <?php $file_val = trim((string) $doc[$col]); ?>
                            <td>
                                <select class="form-control form-control-sm ds-select ds-file-select" name="<?php echo $col; ?>[]" data-kind="<?php echo $kind; ?>" data-saved="<?php echo htmlspecialchars($file_val); ?>" required>
                                    <option value="<?php echo htmlspecialchars($file_val); ?>" selected><?php echo htmlspecialchars($file_val); ?></option>
                                </select>
                            </td>
                            <?php endforeach; ?>
                            <td style="text-align:center;">
                                <button type="button" class="btn btn-sm btn-action-delete" onclick="deleteRow(this, '<?php echo $doc['id']; ?>')">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                            <input type="hidden" name="row_id[]" value="<?php echo $doc['id']; ?>">
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>

<script>
    // doc_code → array of doc_types mapping (from doc_type_master)
    var DOC_TYPE_MAP = <?php echo json_encode($doc_type_map); ?>;
    // doc_code → doc_types already saved in document_setup
    var SAVED_TYPE_MAP = <?php echo json_encode((object) $saved_type_map); ?>;
    // doc_code → { header: [], detail: [], print: [] } existing file names (without .php)
    var DOC_FILE_MAP = <?php echo json_encode((object) $doc_file_map); ?>;
    // doc_code → print files already saved (used while the module has no print files)
    var SAVED_PRINT_MAP = <?php echo json_encode((object) $saved_print_map); ?>;
    // module folder → { header: [], detail: [], print: [] } for codes not yet linked to a module
    var MODULE_FILE_MAP = <?php echo json_encode((object) $module_file_map); ?>;

    // Types of this code that are saved, or picked in another row, are not offered again
    function usedDocTypes(code, row) {
        var ownSaved = (row.querySelector('select[name="doc_type[]"]').dataset.savedType) || '';
        var used = (SAVED_TYPE_MAP[code] || []).filter(function (t) { return t !== ownSaved; });
        document.querySelectorAll('#documentsTableBody tr').forEach(function (other) {
            if (other === row) return;
            var oc = other.querySelector('select[name="doc_code[]"]');
            var ot = other.querySelector('select[name="doc_type[]"]');
            if (oc && ot && oc.value === code && ot.value) used.push(ot.value);
        });
        return used;
    }

    // Header / Detail / Print dropdowns list the module's existing files for the row's code
    function fillFileSelects(row) {
        var codeSelect = row.querySelector('select[name="doc_code[]"]');
        var code = codeSelect ? codeSelect.value : '';
        row.querySelectorAll('select.ds-file-select').forEach(function (sel) {
            var kind = sel.dataset.kind;
            var saved = sel.dataset.saved || '';
            var current = sel.value || saved;
            var files = (code && DOC_FILE_MAP[code] && DOC_FILE_MAP[code][kind]) ? DOC_FILE_MAP[code][kind].slice() : [];
            if (kind === 'print' && files.length === 0) {
                files = (SAVED_PRINT_MAP[code] || []).slice();
                if (saved && files.indexOf(saved) === -1) files.push(saved);
            }
            var label = kind.charAt(0).toUpperCase() + kind.slice(1);
            if (code && !DOC_FILE_MAP[code]) {
                sel.innerHTML = '<option value="">' + label + '</option>';
                Object.keys(MODULE_FILE_MAP).forEach(function (mod) {
                    var modFiles = MODULE_FILE_MAP[mod][kind] || [];
                    if (!modFiles.length) return;
                    var group = document.createElement('optgroup');
                    group.label = mod;
                    modFiles.forEach(function (f) {
                        var opt = document.createElement('option');
                        opt.value = f;
                        opt.textContent = f;
                        if (f === current) opt.selected = true;
                        group.appendChild(opt);
                    });
                    sel.appendChild(group);
                });
                return;
            }
            sel.innerHTML = files.length === 1 ? '' : '<option value="">' + (code ? label : 'Select Code first') + '</option>';
            files.forEach(function (f) {
                var opt = document.createElement('option');
                opt.value = f;
                opt.textContent = f;
                if (f === current) opt.selected = true;
                sel.appendChild(opt);
            });
            if (files.length === 1) sel.value = files[0];
        });
    }

    // Filter the doc_type dropdown in the same row based on selected doc_code
    function filterDocTypes(codeSelect) {
        var code = codeSelect.value;
        var row = codeSelect.closest('tr');
        var typeSelect = row.querySelector('select[name="doc_type[]"]');
        if (!typeSelect) return;
        fillFileSelects(row);
        var currentVal = typeSelect.value;
        var used = code ? usedDocTypes(code, row) : [];
        var allTypes = (code && DOC_TYPE_MAP[code]) ? DOC_TYPE_MAP[code] : [];
        var types = allTypes.filter(function (t) { return used.indexOf(t) === -1; });
        typeSelect.innerHTML = '<option value="">'
            + (types.length ? 'Select Type' : (allTypes.length ? 'All types saved' : 'Select Code first'))
            + '</option>';
        types.forEach(function(t) {
            var opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if (t === currentVal) opt.selected = true;
            typeSelect.appendChild(opt);
        });
        // Auto-select if only one type available
        if (types.length === 1) typeSelect.value = types[0];
    }

    // On page load: filter existing rows so each type dropdown matches its saved code
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('select[name="doc_code[]"]').forEach(function(sel) {
            filterDocTypes(sel);
        });
    });

    let deletedRows = [];
    
    function showAlert(type, message) {
        const alertId = type === 'success' ? 'successAlert' : 'errorAlert';
        const messageId = type === 'success' ? 'successMessage' : 'errorMessage';
        
        document.getElementById(messageId).textContent = message;
        const alertEl = document.getElementById(alertId);
        alertEl.classList.add('show');
        
        setTimeout(() => {
            alertEl.classList.remove('show');
        }, 4000);
    }
    
    function toggleSelectAll(checkbox) {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => cb.checked = checkbox.checked);
    }
    
    function addNewRow() {
        const rowCount = document.querySelectorAll('#documentsTableBody tr').length;
        const formData = new FormData();
        formData.append('action', 'add_row');
        formData.append('row_count', rowCount);
        
        fetch('document_setup.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById('documentsTableBody');
                // Remove empty state row if exists
                const emptyRow = tbody.querySelector('tr td[colspan="8"]');
                if (emptyRow) {
                    emptyRow.closest('tr').remove();
                }
                tbody.insertAdjacentHTML('beforeend', data.html);
                // Filter the type dropdown on the new row (starts empty, so shows placeholder)
                const newRow = tbody.lastElementChild;
                const codeSelect = newRow.querySelector('select[name="doc_code[]"]');
                if (codeSelect) filterDocTypes(codeSelect);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showAlert('danger', 'Error adding new row');
        });
    }
    
    function removeRow(button) {
        button.closest('tr').remove();
    }
    
    function deleteRow(button, rowId) {
        if (!confirm('Are you sure you want to delete this record?')) {
            return;
        }
        
        if (rowId.toString().startsWith('new_')) {
            button.closest('tr').remove();
            showAlert('success', 'Row removed');
        } else {
            fetch('document_setup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=delete&id=' + encodeURIComponent(rowId)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    button.closest('tr').remove();
                    showAlert('success', data.message);
                } else {
                    showAlert('danger', data.message);
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('danger', 'Error deleting record');
            });
        }
    }
    
    function deleteSelected() {
        const selected = document.querySelectorAll('.row-checkbox:checked');
        if (selected.length === 0) {
            showAlert('danger', 'Please select rows to delete');
            return;
        }
        
        if (!confirm('Are you sure you want to delete ' + selected.length + ' record(s)?')) {
            return;
        }
        
        selected.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const rowId = row.querySelector('input[name="row_id[]"]').value;
            
            if (!rowId.toString().startsWith('new_')) {
                fetch('document_setup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=delete&id=' + encodeURIComponent(rowId)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        row.remove();
                    }
                })
                .catch(err => console.error('Error:', err));
            } else {
                row.remove();
            }
        });
        
        setTimeout(() => {
            showAlert('success', 'Selected records deleted');
            document.getElementById('selectAll').checked = false;
        }, 500);
    }
    
    function saveChanges() {
        const form = document.getElementById('documentSetupForm');
        const formData = new FormData(form);
        formData.append('action', 'save');
        
        const rows = document.querySelectorAll('#documentsTableBody tr');
        if (rows.length === 0) {
            showAlert('danger', 'No records to save');
            return;
        }
        
        // Validate all fields
        let isValid = true;
        rows.forEach(row => {
            const inputs = row.querySelectorAll('input[type="text"], select');
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = '#ef4444';
                } else {
                    input.style.borderColor = '';
                }
            });
        });
        
        if (!isValid) {
            showAlert('danger', 'Please fill in all required fields');
            return;
        }
        
        fetch('document_setup.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message);
                // Reload page after 1 second
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert('danger', data.message);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showAlert('danger', 'Error saving records');
        });
    }
    
    function resetForm() {
        if (confirm('Are you sure you want to reset all changes?')) {
            location.reload();
        }
    }
</script>

</body>
</html>
