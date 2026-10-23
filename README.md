# ERP Web Application — Architecture & Implementation Guide

---

## Table of Contents
1. [Project Overview](#1-project-overview)
2. [Folder Structure](#2-folder-structure)
3. [Core Architectural Concepts](#3-core-architectural-concepts)
4. [Q1 — Toolbar as a Separate Module](#4-q1--toolbar-as-a-separate-module)
5. [Q2 — Header & Detail Windows per Module](#5-q2--header--detail-windows-per-module)
6. [Q3 — The Container File (invoice.php)](#6-q3--the-container-file-invoicephp)
7. [Q4 — Toolbar Functionality Across Both Windows](#7-q4--toolbar-functionality-across-both-windows)
8. [Correct Implementation Pattern](#8-correct-implementation-pattern)
9. [Database Tables Involved](#9-database-tables-involved)
10. [Summary Diagram](#10-summary-diagram)

---

## 1. Project Overview

This is a **multi-company, multi-branch ERP web application** built in PHP with a SQL Server backend. It manages business documents such as Sales Invoices, Purchase Orders, Material Requests, etc. Each document type is fully configurable — which UI file to use for the header and detail sections is driven by a `document_setup` database table.

---

## 2. Folder Structure

```
Approval/
│
├── db.php                          # PDO database connection
├── auth.php                        # Session authentication guard
├── approvalmenu.php                # Main navigation/menu
├── document_setup.php              # Admin: map doc_code+doc_type → header/detail files
│
├── modules/
│   ├── invoice/
│   │   ├── invoice.php             # CONTAINER — loads correct header+detail based on doc_type
│   │   ├── header/
│   │   │   ├── sales_invoice_h_01.php   # Header window variant 1 (Credit Sales)
│   │   │   ├── sales_invoice_h_02.php   # Header window variant 2 (e.g. cash sales custom layout)
│   │   │   └── ...
│   │   └── detail/
│   │       ├── sales_invoice_d_01.php   # Detail/line items window variant 1
│   │       ├── sales_invoice_d_02.php   # Detail/line items window variant 2
│   │       └── ...
│   │
│   ├── purchase_order/
│   │   ├── purchase_order.php
│   │   ├── header/
│   │   └── detail/
│   │
│   └── material_request/
│       ├── material_request.php
│       ├── header/
│       └── detail/
│
├── shared/
│   ├── toolbar.php                 # SHARED TOOLBAR MODULE (see Section 4)
│   ├── header.php                  # Top navigation bar
│   └── auth.php                    # Auth include
│
└── vendor/                         # JS/CSS libraries
```

---

## 3. Core Architectural Concepts

The entire ERP is built around three ideas:

| Concept | What it means |
|---|---|
| **document_setup table** | Tells the system WHICH PHP files to load for a given `doc_code` + `doc_type` |
| **Container file** | One PHP file per module (e.g. `invoice.php`) that reads `document_setup` and `include()`s the right header and detail files |
| **Toolbar module** | A single shared PHP file that renders the toolbar buttons and fires events that the active windows listen to |

---

## 4. Q1 — Toolbar as a Separate Module

### Answer: YES — the toolbar MUST be a separate module.

The toolbar (NEW, SAVE, EDIT, DELETE, FIND, PRINT, etc.) is identical in layout across all document windows. If you embed it inside every header file separately, you will have to maintain it in dozens of places.

### Correct approach: `shared/toolbar.php`

The toolbar file is included once by the **container file** (`invoice.php`), not by the individual header/detail files. It renders the buttons and fires JavaScript **custom events** or calls **global JS callbacks** that the active header/detail windows register.

**`shared/toolbar.php`**
```php
<?php
/**
 * Shared Toolbar
 * Expects: $is_view_mode (bool), $selected_doc_type (string)
 * The active window (header or detail) must register:
 *   window.erpToolbar.onSave, onEdit, onDelete, onNew, onFind, onPrint, onPrior, onNext
 */
?>
<div class="toolbar" id="erpToolbar">
    <button type="button" class="toolbar-btn" id="tbNew"     onclick="erpToolbarAction('new')"><i class="fa fa-file-o text-warning"></i><span>NEW</span></button>
    <button type="button" class="toolbar-btn" id="tbSave"    onclick="erpToolbarAction('save')"><i class="fa fa-save text-dark"></i><span>SAVE</span></button>
    <button type="button" class="toolbar-btn" id="tbFind"    onclick="erpToolbarAction('find')"><i class="fa fa-binoculars text-info"></i><span>FIND</span></button>
    <button type="button" class="toolbar-btn" id="tbEdit"    onclick="erpToolbarAction('edit')"><i class="fa fa-pencil-square-o text-primary"></i><span>EDIT</span></button>
    <button type="button" class="toolbar-btn" id="tbDelete"  onclick="erpToolbarAction('delete')"><i class="fa fa-trash text-dark"></i><span>DELETE</span></button>
    <button type="button" class="toolbar-btn" id="tbPreview" onclick="erpToolbarAction('preview')"><i class="fa fa-file-text-o text-info"></i><span>PREVIEW</span></button>
    <button type="button" class="toolbar-btn" id="tbPrint"   onclick="erpToolbarAction('print')"><i class="fa fa-print text-info"></i><span>PRINT</span></button>
    <button type="button" class="toolbar-btn" id="tbPrior"   onclick="erpToolbarAction('prior')"><i class="fa fa-arrow-left text-success"></i><span>PRIOR</span></button>
    <button type="button" class="toolbar-btn" id="tbNext"    onclick="erpToolbarAction('next')"><i class="fa fa-arrow-right text-success"></i><span>NEXT</span></button>
    <button type="button" class="toolbar-btn" id="tbCancel"  onclick="erpToolbarAction('cancel')"><i class="fa fa-eraser text-warning"></i><span>CANCEL</span></button>
    <button type="button" class="toolbar-btn" id="tbAttach"  onclick="erpToolbarAction('attach')"><i class="fa fa-paperclip text-info"></i><span>ATTACH</span></button>
    <button type="button" class="toolbar-btn text-danger" onclick="window.location.href='<?php echo htmlspecialchars($close_url ?? '../approvalmenu.php'); ?>'">
        <i class="fa fa-times text-dark"></i><span>CLOSE</span>
    </button>
</div>

<script>
// Global toolbar event dispatcher
// Each window (header/detail) registers its handlers by setting these functions
window.erpToolbar = {
    onNew:     function() {},
    onSave:    function() {},
    onFind:    function() {},
    onEdit:    function() {},
    onDelete:  function() {},
    onPreview: function() {},
    onPrint:   function() {},
    onPrior:   function() {},
    onNext:    function() {},
    onCancel:  function() {},
    onAttach:  function() {}
};

function erpToolbarAction(action) {
    if (typeof window.erpToolbar['on' + action.charAt(0).toUpperCase() + action.slice(1)] === 'function') {
        window.erpToolbar['on' + action.charAt(0).toUpperCase() + action.slice(1)]();
    }
}

// Helper: enable/disable toolbar buttons from any window
function erpToolbarSetState(states) {
    // states = { save: true, edit: false, delete: true, ... }
    const map = { new:'tbNew', save:'tbSave', find:'tbFind', edit:'tbEdit',
                  delete:'tbDelete', preview:'tbPreview', print:'tbPrint',
                  prior:'tbPrior', next:'tbNext', cancel:'tbCancel', attach:'tbAttach' };
    for (const [action, enabled] of Object.entries(states)) {
        const btn = document.getElementById(map[action]);
        if (btn) btn.disabled = !enabled;
    }
}
</script>
```

---

## 5. Q2 — Header & Detail Windows per Module

### Answer: Yes — each module has a `header/` and `detail/` folder with numbered variants.

### Why multiple header/detail files?

Different customers or business needs require different layouts for the same document type. For example:
- **`sales_invoice_h_01.php`** — Standard Credit Sales header
- **`sales_invoice_h_02.php`** — Header with extra fields for a specific customer (e.g. project billing, warranty fields)
- **`sales_invoice_d_01.php`** — Standard line items grid
- **`sales_invoice_d_02.php`** — Line items with BOM explosion, serial numbers, etc.

The choice of which file to use is **not hardcoded** — it is stored in the `document_setup` table so the admin can configure it without touching code.

### Rules for header/detail files

| Rule | Reason |
|---|---|
| Do NOT include the toolbar in header/detail files | The toolbar is injected by the container |
| Do NOT include `<html>`, `<head>`, `<body>` tags | The container provides the page shell |
| DO register toolbar handlers in a `<script>` block | So toolbar buttons work for that window |
| DO expose a `getFormData()` JS function | So the container can collect data for saving |

### Example — `modules/invoice/header/sales_invoice_h_01.php` (stripped to key pattern)

```php
<?php
// Only PHP logic here — no full HTML page shell
// All DB queries, data loading, etc.
$doc_no = ...;
$ac_code = ...;
?>

<!-- Only the form content, no <html>/<head>/<body> -->
<div class="si-form-wrap">
    <form id="siHeaderForm">
        ...fields...
    </form>
</div>

<script>
// Register toolbar handlers for THIS window
window.erpToolbar.onSave = function() {
    document.getElementById('siHeaderForm').submit();
};
window.erpToolbar.onEdit = function() {
    // Enable all readonly fields
    document.querySelectorAll('[readonly]').forEach(el => el.removeAttribute('readonly'));
    erpToolbarSetState({ save: true, edit: false, delete: true });
};
window.erpToolbar.onNew = function() {
    window.location.href = '?type=<?php echo urlencode($selected_doc_type); ?>';
};
window.erpToolbar.onPrint = function() {
    window.open('print_invoice.php?doc_no=<?php echo urlencode($doc_no); ?>', '_blank');
};
</script>
```

---

## 6. Q3 — The Container File (invoice.php)

### Answer: The container is the SINGLE entry point for a module. It reads document_setup and includes the correct header and detail files.

### How it works

1. User navigates to `modules/invoice/invoice.php?doc_type=CRS&doc_no=CRS/00001`
2. Container queries `document_setup` table: *"For doc_code=SI, doc_type=CRS, what are the header and detail files?"*
3. Result: `header_file = sales_invoice_h_01`, `detail_file = sales_invoice_d_01`
4. Container includes `header/sales_invoice_h_01.php` and `detail/sales_invoice_d_01.php`
5. Both windows share the same toolbar, same session, same page

### `modules/invoice/invoice.php` — Full implementation pattern

```php
<?php
ob_start();
require('../../db.php');
include('../../auth.php');

$company_code = $_SESSION['company_code'];
$branch_code  = $_SESSION['branch_code'];
$user         = $_SESSION['username'];

// Read doc_type from URL (default: CRS)
$doc_type = isset($_GET['doc_type']) ? strtoupper(trim($_GET['doc_type'])) : 'CRS';
$doc_no   = isset($_GET['doc_no'])   ? trim($_GET['doc_no'])               : '';

// Look up document_setup to find which header/detail files to load
$header_file = null;
$detail_file = null;
try {
    $stmt = $con->prepare(
        "SELECT header_file, detail_file
         FROM document_setup
         WHERE company_code = ? AND branch_code = ? AND doc_code = 'SI' AND doc_type = ?"
    );
    $stmt->execute([$company_code, $branch_code, $doc_type]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $header_file = trim($row['header_file']);  // e.g. "sales_invoice_h_01"
        $detail_file = trim($row['detail_file']);  // e.g. "sales_invoice_d_01"
    }
} catch (Exception $e) {
    error_log("document_setup lookup error: " . $e->getMessage());
}

// Fallback defaults if not configured
if (!$header_file) $header_file = 'sales_invoice_h_01';
if (!$detail_file) $detail_file = 'sales_invoice_d_01';

// Security: validate file names (only alphanumeric, underscore, hyphen — NO path traversal)
$header_file = preg_replace('/[^a-zA-Z0-9_\-]/', '', $header_file);
$detail_file = preg_replace('/[^a-zA-Z0-9_\-]/', '', $detail_file);

$header_path = __DIR__ . '/header/' . $header_file . '.php';
$detail_path = __DIR__ . '/detail/' . $detail_file . '.php';

$close_url = '../../approvalmenu.php';
$is_view_mode = ($doc_no !== '');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Invoice</title>
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../material_request.css?v=5">
</head>
<body>

    <!-- ① TOOLBAR — included once, shared by both header and detail -->
    <?php include('../../shared/toolbar.php'); ?>

    <div class="container-fluid">
    <div class="mr-container">

        <!-- ② HEADER WINDOW -->
        <?php
        if (file_exists($header_path)) {
            include($header_path);
        } else {
            echo '<div class="alert alert-danger">Header file not found: ' . htmlspecialchars($header_file) . '</div>';
        }
        ?>

        <!-- ③ DETAIL WINDOW (line items grid) -->
        <?php
        if (file_exists($detail_path)) {
            include($detail_path);
        } else {
            echo '<div class="alert alert-danger">Detail file not found: ' . htmlspecialchars($detail_file) . '</div>';
        }
        ?>

    </div>
    </div>

    <script src="../../vendor/jquery/jquery.min.js"></script>
    <script src="//maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
</body>
</html>
```

---

## 7. Q4 — Toolbar Functionality Across Both Windows

### Answer: Yes — the toolbar works for BOTH windows simultaneously, using the JS callback pattern.

### How the toolbar communicates with header and detail

The toolbar fires **JavaScript callbacks** (`window.erpToolbar.onSave`, `onEdit`, etc.). Each window (header and detail) registers its own logic for those callbacks. Because they run on the same page, both can respond.

### SAVE — coordinating header + detail

The SAVE action must save BOTH header fields and detail line items together. The recommended pattern is:

```javascript
// In the container file's <script> block (invoice.php), after including both windows:
window.erpToolbar.onSave = function() {
    // 1. Validate header form
    const headerForm = document.getElementById('siHeaderForm');
    if (!headerForm.reportValidity()) return;

    // 2. Collect header data + detail data and POST together
    const headerData = new FormData(headerForm);
    const detailRows = window.siDetailGetRows(); // function exposed by detail window
    headerData.append('detail_rows', JSON.stringify(detailRows));
    headerData.append('save_invoice', '1');

    fetch('invoice.php', { method: 'POST', body: headerData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'invoice.php?doc_type=<?php echo urlencode($doc_type); ?>&doc_no=' + data.doc_no;
            }
        });
};
```

### EDIT / VIEW mode toggle

```javascript
// Toolbar fires onEdit — both windows react
window.erpToolbar.onEdit = function() {
    // Header window exposes this function
    if (typeof siHeaderEnableEdit === 'function') siHeaderEnableEdit();
    // Detail window exposes this function
    if (typeof siDetailEnableEdit === 'function') siDetailEnableEdit();
    // Update toolbar button states
    erpToolbarSetState({ save: true, edit: false, delete: false, cancel: true });
};
```

### PRIOR / NEXT navigation

```javascript
// Toolbar fires onPrior — navigate to previous document
window.erpToolbar.onPrior = function() {
    // The header window exposes the current doc_num
    const currentNum = parseInt(document.getElementById('hdnDocNum').value);
    if (currentNum > 1) {
        window.location.href = 'invoice.php?doc_type=<?php echo urlencode($doc_type); ?>&doc_num=' + (currentNum - 1);
    }
};
```

---

## 8. Correct Implementation Pattern

### Step-by-step guide

#### Step 1 — Create `shared/toolbar.php`
One file. All modules include it via the container. Contains the toolbar HTML and the `window.erpToolbar` dispatcher object.

#### Step 2 — Create the module container (e.g. `modules/invoice/invoice.php`)
- Reads `document_setup` to find `header_file` and `detail_file`
- Outputs the page shell: `<html>`, `<head>`, CSS/JS references
- Includes toolbar
- Includes header window file
- Includes detail window file
- Registers the save/edit/delete coordination logic in a `<script>` block

#### Step 3 — Create header window files (`modules/invoice/header/sales_invoice_h_01.php`)
- PHP logic only (no page shell)
- Form HTML for the header fields
- `<script>` block that registers `window.erpToolbar.onNew`, `onEdit`, `onCancel`
- Exposes a JS function like `siHeaderGetData()` so the container can collect data

#### Step 4 — Create detail window files (`modules/invoice/detail/sales_invoice_d_01.php`)
- PHP logic only (no page shell)
- Table/grid HTML for line items
- `<script>` block that registers any detail-specific toolbar handlers
- Exposes `siDetailGetRows()` returning an array of row objects

#### Step 5 — Configure document_setup
In the admin screen (`document_setup.php`), add a row:

| company_code | branch_code | doc_code | doc_type | header_file | detail_file | print_file |
|---|---|---|---|---|---|---|
| CO01 | BR01 | SI | CRS | sales_invoice_h_01 | sales_invoice_d_01 | sales_invoice_print_01 |
| CO01 | BR01 | SI | CSH | sales_invoice_h_02 | sales_invoice_d_01 | sales_invoice_print_01 |

Now `invoice.php?doc_type=CRS` loads `h_01` and `invoice.php?doc_type=CSH` loads `h_02` — **no code changes needed**.

#### Step 6 — Tab-based navigation between Header and Detail (optional)

If the header and detail are shown as two tabs on the same page rather than stacked vertically:

```html
<!-- In invoice.php container -->
<ul class="nav nav-tabs" id="invoiceTabs">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tabHeader">Header</a></li>
    <li class="nav-item"><a class="nav-link"        data-toggle="tab" href="#tabDetail">Detail / Items</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane active" id="tabHeader"><?php include($header_path); ?></div>
    <div class="tab-pane"        id="tabDetail"><?php include($detail_path); ?></div>
</div>
```

The toolbar still sits above the tabs and works for both.

---

## 9. Database Tables Involved

| Table | Purpose |
|---|---|
| `document_setup` | Maps doc_code + doc_type → header_file, detail_file, print_file |
| `doc_type_master` | All valid doc types for a company/branch (SI=Credit Sales, PI=Purchase Invoice, etc.) |
| `doc_number` | Prefix/formatting rules for auto-generated document numbers |
| `invoice_h` | Header records for all sales invoice types |
| `invoice_d` | Detail/line item records for all sales invoices |
| `ac_master` | Customer and supplier account master |
| `currency_master` | Currency codes and exchange rates |
| `salesman` | Salesman master linked to login users |
| `branch_user` | Maps login users to branches |

---

## 10. Summary Diagram

```
URL: invoice.php?doc_type=CRS&doc_no=CRS/00001
         │
         ▼
┌─────────────────────────────────────────────────┐
│           invoice.php  (CONTAINER)              │
│                                                 │
│  1. Query document_setup                        │
│     doc_code=SI, doc_type=CRS                   │
│     → header_file = sales_invoice_h_01          │
│     → detail_file = sales_invoice_d_01          │
│                                                 │
│  2. Render page shell (html/head/body)          │
│                                                 │
│  ┌───────────────────────────────────────────┐  │
│  │      shared/toolbar.php                   │  │
│  │  [NEW][SAVE][FIND][EDIT][DELETE]...        │  │
│  │  window.erpToolbar.onSave = coordinator   │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
│  ┌───────────────────────────────────────────┐  │
│  │   header/sales_invoice_h_01.php           │  │
│  │   (Invoice No, Customer, Dates, Currency) │  │
│  │   Registers: erpToolbar.onEdit / onCancel │  │
│  │   Exposes:   siHeaderGetData()            │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
│  ┌───────────────────────────────────────────┐  │
│  │   detail/sales_invoice_d_01.php           │  │
│  │   (Line items: item, qty, price, total)   │  │
│  │   Exposes:   siDetailGetRows()            │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Key Principle

> **The container owns the page. The toolbar owns the actions. The header/detail windows own the data. They communicate through clearly defined JavaScript callbacks.**

This means:
- Adding a new header variant (`h_02`) = create one new file + add one row in `document_setup`. Zero changes to the container or toolbar.
- Adding a new toolbar button = change only `shared/toolbar.php`. Zero changes to header/detail files.
- Changing save logic = change the coordinator in `invoice.php`. Zero changes to header/detail.
