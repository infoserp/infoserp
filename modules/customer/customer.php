<?php
/**
 * Master — Create / Search / Edit Customer (single file, no document_setup).
 * Menu: user_menu.menu_code = m_ma-customer (parent Master).
 *
 * Logic adapted from ERP create_customer: create form, search modal, detail edit/delete.
 */
ob_start();
require __DIR__ . '/../../db.php';
include __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../includes/erp_paths.php';
date_default_timezone_set('Asia/Dubai');

// AJAX endpoints must not be redirected by the shell guard (search is GET).
$cus_ajax = strtolower(trim((string) ($_GET['action'] ?? '')));
$cus_is_ajax = in_array($cus_ajax, ['search', 'update', 'delete'], true);
if (!$cus_is_ajax) {
    erp_guard_standalone_get();
}

$pdo = isset($con) ? $con : (isset($conn) ? $conn : null);
if (!$pdo) {
    die('Database connection not available');
}

if (($_SESSION['CUS'] ?? 'N') !== 'Y') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(403);
    if ($cus_is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    echo 'Access denied. Enable menu m_ma-customer (Master → Customer) for this user.';
    exit;
}

$user_type = isset($_SESSION['user_type']) ? trim((string) $_SESSION['user_type']) : '';
if ($user_type !== 'A' && $user_type !== 'U') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($cus_is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    header('Location: ' . erp_url('login'));
    exit;
}

$user_id      = (string) ($_SESSION['username'] ?? '');
$company_code = $_SESSION['company_code'] ?? '';
$branch_code  = $_SESSION['branch_code'] ?? '';
// Keep embed=1 so fetch() stays inside the iframe shell rules.
$self_url     = erp_iframe_url('modules/customer/customer');

// Delete is allowed only for logins with branch_user.allow_delete = 'Y' in this branch.
$stmt = $pdo->prepare('SELECT allow_delete FROM branch_user WHERE branch_code = ? AND user_id = ?');
$stmt->execute([(string) $branch_code, $user_id]);
$can_delete = (strtoupper(trim((string) $stmt->fetchColumn())) === 'Y');

$message      = '';
$message_type = '';

define('CUST_NAME_MAX', 150); // description nvarchar(150)

/**
 * The editable customer fields, in display order.
 * One list drives create, detail and edit so fields cannot drift apart.
 */
$FIELDS = [
    ['col' => 'zip_code',              'label' => 'Postal Code',    'max' => 50,   'type' => 'text',     'section' => 'address'],
    ['col' => 'address',               'label' => 'Address',        'max' => 250,  'type' => 'text',     'section' => 'address', 'span' => 2],
    ['col' => 'city',                  'label' => 'City',           'max' => 50,   'type' => 'text',     'section' => 'address'],
    ['col' => 'country',               'label' => 'Country',        'max' => 50,   'type' => 'text',     'section' => 'address'],
    ['col' => 'service_location_code', 'label' => 'Service Loc',    'max' => 5,    'type' => 'select',   'section' => 'address'],
    ['col' => 'email',                 'label' => 'E-Mail',         'max' => 50,   'type' => 'text',     'section' => 'address'],
    ['col' => 'www',                   'label' => 'Website',        'max' => 50,   'type' => 'text',     'section' => 'address'],

    ['col' => 'tel_off',               'label' => 'Tel. (Off)',     'max' => 50,   'type' => 'text',     'section' => 'contact'],
    ['col' => 'tel_res',               'label' => 'Tel. (Res)',     'max' => 50,   'type' => 'text',     'section' => 'contact'],
    ['col' => 'fax_off',               'label' => 'Fax (Off)',      'max' => 50,   'type' => 'text',     'section' => 'contact'],
    ['col' => 'contact_name',          'label' => 'Contact Person', 'max' => 50,   'type' => 'text',     'section' => 'contact', 'required' => true],
    ['col' => 'contact_designation',   'label' => 'Designation',    'max' => 50,   'type' => 'text',     'section' => 'contact'],
    ['col' => 'contact_no',            'label' => 'Mobile No',      'max' => 50,   'type' => 'text',     'section' => 'contact', 'required' => true],
    ['col' => 'contact_email',         'label' => 'E-Mail',         'max' => 50,   'type' => 'text',     'section' => 'contact'],

    ['col' => 'tax_no',                'label' => 'TRN',            'max' => 50,   'type' => 'text',     'section' => 'identity', 'class' => 'cf-trn'],

    ['col' => 'active',                'label' => 'Active',         'max' => 1,    'type' => 'checkbox', 'section' => 'other'],
    ['col' => 'project',               'label' => 'Project',        'max' => 250,  'type' => 'text',     'section' => 'other'],
    ['col' => 'payment_terms',         'label' => 'Pay. Terms',     'max' => 250,  'type' => 'text',     'section' => 'other'],
    ['col' => 'remark',                'label' => 'Remark',         'max' => 2000, 'type' => 'textarea', 'section' => 'other'],
];

$SECTIONS = [
    'address' => 'Address',
    'contact' => 'Contact Details',
    'other'   => 'Other',
];

// Same definition of "customer" the rest of the app uses (SI search).
$customer_filter = "company_code = ?
                    AND child_exist = 'N'
                    AND parent_account_code IN (
                          SELECT a.account_code
                          FROM ac_master a
                          INNER JOIN parent_control p
                              ON a.company_code = p.company_code
                             AND a.ac_code      = p.ac_code
                          WHERE p.company_code = ?
                            AND p.parent_type  = 'C'
                    )";

$detail_cols = array_merge(['description'], array_column($FIELDS, 'col'));

function cus_strlen($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/** One customer of this company as the detail window expects it, or null. */
function fetch_customer($pdo, $customer_filter, $detail_cols, $company_code, $ac_code)
{
    $stmt = $pdo->prepare('SELECT ac_code, ' . implode(', ', $detail_cols)
                        . " FROM ac_master WHERE $customer_filter AND ac_code = ?");
    $stmt->execute([$company_code, $company_code, $ac_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $out = ['ac_code' => trim($row['ac_code'])];
    foreach ($detail_cols as $c) {
        $out[$c] = ($row[$c] === null) ? '' : trim((string) $row[$c]);
    }
    return $out;
}

function cus_json_exit(array $payload)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------------------
//  Update (JSON) — POST ?action=update
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cus_json_exit(['success' => false, 'message' => 'Invalid request method.']);
    }

    $ac_code = trim($_POST['ac_code'] ?? '');
    if ($ac_code === '') {
        cus_json_exit(['success' => false, 'message' => 'Customer code is missing.']);
    }

    $editable = array_merge(
        [['col' => 'description', 'label' => 'Customer Name', 'max' => CUST_NAME_MAX, 'type' => 'text', 'required' => true]],
        $FIELDS
    );

    $sets   = [];
    $params = [];

    foreach ($editable as $f) {
        $val = trim($_POST[$f['col']] ?? '');

        if ($f['type'] === 'checkbox') {
            $val = ($val === 'Y') ? 'Y' : 'N';
        } else {
            if (!empty($f['required']) && $val === '') {
                cus_json_exit(['success' => false, 'message' => $f['label'] . ' is required.']);
            }
            if ($val !== '' && cus_strlen($val) > $f['max']) {
                cus_json_exit(['success' => false, 'message' => $f['label'] . ' must be ' . $f['max'] . ' characters or fewer.']);
            }
            if ($val === '') {
                $val = null;
            }
        }

        $sets[]   = $f['col'] . ' = ?';
        $params[] = $val;
    }

    try {
        if (!fetch_customer($pdo, $customer_filter, $detail_cols, $company_code, $ac_code)) {
            cus_json_exit(['success' => false, 'message' => 'Customer not found.']);
        }

        $sets[]   = 'last_userid = ?';
        $params[] = $user_id;
        $sets[]   = 'last_userdate = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $company_code;
        $params[] = $ac_code;

        $pdo->prepare('UPDATE ac_master SET ' . implode(', ', $sets) . ' WHERE company_code = ? AND ac_code = ?')
            ->execute($params);

        cus_json_exit([
            'success' => true,
            'message' => 'Changes saved.',
            'data'    => fetch_customer($pdo, $customer_filter, $detail_cols, $company_code, $ac_code),
        ]);
    } catch (PDOException $e) {
        error_log('Update customer error: ' . $e->getMessage());
        cus_json_exit(['success' => false, 'message' => 'Save failed.']);
    }
}

// ---------------------------------------------------------------------------
//  Delete (JSON) — POST ?action=delete
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cus_json_exit(['success' => false, 'message' => 'Invalid request method.']);
    }

    if (!$can_delete) {
        cus_json_exit(['success' => false, 'message' => 'You do not have permission to delete.']);
    }

    $ac_code = trim($_POST['ac_code'] ?? '');
    if ($ac_code === '') {
        cus_json_exit(['success' => false, 'message' => 'Customer code is missing.']);
    }

    try {
        $customer = fetch_customer($pdo, $customer_filter, $detail_cols, $company_code, $ac_code);
        if (!$customer) {
            cus_json_exit(['success' => false, 'message' => 'Customer not found.']);
        }

        $blockers = [];
        foreach (['job_card_h' => 'job card', 'task_master' => 'task'] as $table => $noun) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE company_code = ? AND ac_code = ?");
                $st->execute([$company_code, $ac_code]);
                $n = (int) $st->fetchColumn();
                if ($n > 0) {
                    $blockers[] = $n . ' ' . $noun . ($n === 1 ? '' : 's');
                }
            } catch (PDOException $e) {
                // Table may be absent on some DBs
            }
        }

        if ($blockers) {
            cus_json_exit([
                'success' => false,
                'message' => 'This customer cannot be deleted because it is still used by ' . implode(' and ', $blockers) . '.',
            ]);
        }

        $del = $pdo->prepare('DELETE FROM ac_master WHERE company_code = ? AND ac_code = ?');
        $del->execute([$company_code, $ac_code]);

        cus_json_exit([
            'success' => true,
            'message' => 'Customer ' . $ac_code . ($customer['description'] !== '' ? ' (' . $customer['description'] . ')' : '') . ' deleted.',
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            cus_json_exit([
                'success' => false,
                'message' => 'This customer cannot be deleted because it has transactions recorded against it (invoices, orders, payments or similar).',
            ]);
        }
        error_log('Delete customer error: ' . $e->getMessage());
        cus_json_exit(['success' => false, 'message' => 'Delete failed.']);
    }
}

// ---------------------------------------------------------------------------
//  Search (JSON) — ?action=search
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'search') {
    $limit  = 50;
    $params = [$company_code, $company_code];

    $searchable = [
        'all'          => ['description', 'ac_code', 'contact_name', 'contact_no'],
        'ac_code'      => ['ac_code'],
        'description'  => ['description'],
        'contact_name' => ['contact_name'],
        'contact_no'   => ['contact_no'],
    ];

    try {
        $detail_code = trim($_GET['ac_code'] ?? '');

        if ($detail_code !== '') {
            $out = fetch_customer($pdo, $customer_filter, $detail_cols, $company_code, $detail_code);
            cus_json_exit($out
                ? ['success' => true, 'data' => $out]
                : ['success' => false, 'message' => 'Customer not found.']);
        }

        $term  = trim($_GET['q'] ?? '');
        $field = trim($_GET['field'] ?? 'all');
        if (!isset($searchable[$field])) {
            $field = 'all';
        }

        $where = '';
        if ($term !== '') {
            $parts = [];
            foreach ($searchable[$field] as $col) {
                $parts[]  = $col . ' LIKE ?';
                $params[] = '%' . $term . '%';
            }
            $where = ' AND (' . implode(' OR ', $parts) . ')';
        }

        $stmt = $pdo->prepare('SELECT TOP ' . ($limit + 1) . " ac_code, description, contact_name, contact_no
                               FROM ac_master
                               WHERE $customer_filter $where
                               ORDER BY description");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $truncated = count($rows) > $limit;
        $data = [];
        foreach (array_slice($rows, 0, $limit) as $r) {
            $data[] = [
                'ac_code'      => trim($r['ac_code']),
                'description'  => trim($r['description'] ?? ''),
                'contact_name' => trim($r['contact_name'] ?? ''),
                'contact_no'   => trim($r['contact_no'] ?? ''),
            ];
        }

        cus_json_exit([
            'success'   => true,
            'count'     => count($data),
            'truncated' => $truncated,
            'limit'     => $limit,
            'data'      => $data,
        ]);
    } catch (PDOException $e) {
        error_log('Customer search error: ' . $e->getMessage());
        cus_json_exit(['success' => false, 'message' => 'Search failed.']);
    }
}

function resolve_customer_parent($pdo, $company_code)
{
    $sql = "SELECT TOP 1 a.ac_code, a.account_code
            FROM ac_master a
            INNER JOIN parent_control p
                ON a.company_code = p.company_code
               AND a.ac_code      = p.ac_code
            WHERE p.company_code = ?
              AND p.parent_type  = 'C'
            ORDER BY p.set_default DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function next_customer_code($pdo, $company_code)
{
    $sql = "SELECT ISNULL(MAX(n), 0) + 1 AS next_code
            FROM (
                SELECT CAST(ac_code AS INT) AS n
                FROM ac_master
                WHERE company_code = ?
                  AND LEN(ac_code) > 0
                  AND ac_code NOT LIKE '%[^0-9]%'
                UNION ALL
                SELECT CAST(u_key AS INT)
                FROM ac_master
                WHERE company_code = ?
                  AND u_key IS NOT NULL
            ) AS codes";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_code, $company_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return str_pad((string) $row['next_code'], 5, '0', STR_PAD_LEFT);
}

function fetch_service_locations($pdo)
{
    try {
        $stmt = $pdo->query("SELECT service_location_code, service_location_name
                             FROM service_location
                             ORDER BY sort_order, service_location_name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Service location lookup error: ' . $e->getMessage());
        return [];
    }
}

$service_locations = fetch_service_locations($pdo);

// ---------------------------------------------------------------------------
//  Create
// ---------------------------------------------------------------------------
$posted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = trim($_POST['description'] ?? '');
    $posted['description'] = $customer_name;

    foreach ($FIELDS as $f) {
        if ($f['type'] === 'checkbox') {
            $posted[$f['col']] = ((trim($_POST[$f['col']] ?? '')) === 'Y') ? 'Y' : 'N';
        } else {
            $posted[$f['col']] = trim($_POST[$f['col']] ?? '');
        }
    }

    if ($customer_name === '') {
        $message      = 'Customer Name is required.';
        $message_type = 'error';
    } elseif (cus_strlen($customer_name) > CUST_NAME_MAX) {
        $message      = 'Customer Name must be ' . CUST_NAME_MAX . ' characters or fewer.';
        $message_type = 'error';
    }

    if ($message === '') {
        foreach ($FIELDS as $f) {
            $val = $posted[$f['col']];
            if (!empty($f['required']) && $val === '') {
                $message      = $f['label'] . ' is required.';
                $message_type = 'error';
                break;
            }
            if ($val !== '' && cus_strlen($val) > $f['max']) {
                $message      = $f['label'] . ' must be ' . $f['max'] . ' characters or fewer.';
                $message_type = 'error';
                break;
            }
        }
    }

    if ($message === '') {
        $parent = resolve_customer_parent($pdo, $company_code);

        if (!$parent) {
            $message      = 'No customer parent account is configured for this company '
                          . '(parent_control.parent_type = C). Please set one up first.';
            $message_type = 'error';
        } else {
            $fixed_cols = [
                'company_code', 'ac_code', 'account_code',
                'parent_account_code', 'parent_ac_code', 'description',
                'child_exist', 'approved',
                'u_key', 'user_id', 'user_date',
            ];

            $all_cols = array_merge($fixed_cols, array_column($FIELDS, 'col'));
            $insert_sql = 'INSERT INTO ac_master (' . implode(', ', $all_cols) . ') VALUES ('
                        . implode(', ', array_fill(0, count($all_cols), '?')) . ')';

            $saved    = false;
            $attempts = 0;

            while (!$saved && $attempts < 5) {
                $attempts++;
                try {
                    $new_ac_code = next_customer_code($pdo, $company_code);

                    if (strlen($new_ac_code) > 5) {
                        $message      = 'The account code range is exhausted (ac_code is varchar(5)).';
                        $message_type = 'error';
                        break;
                    }

                    $params = [
                        $company_code,
                        $new_ac_code,
                        $parent['account_code'] . '-' . $new_ac_code,
                        $parent['account_code'],
                        $parent['ac_code'],
                        $customer_name,
                        'N',
                        'Y',
                        (int) $new_ac_code,
                        $user_id,
                        date('Y-m-d H:i:s'),
                    ];

                    foreach ($FIELDS as $f) {
                        $val = $posted[$f['col']];
                        $params[] = ($val === '' ? null : $val);
                    }

                    $pdo->prepare($insert_sql)->execute($params);

                    $saved        = true;
                    $message      = 'Customer created successfully. Customer Code: ' . $new_ac_code;
                    $message_type = 'success';
                    $posted       = [];
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && $attempts < 5) {
                        continue;
                    }
                    error_log('Create customer error: ' . $e->getMessage());
                    $message      = 'Failed to create customer. ' . $e->getMessage();
                    $message_type = 'error';
                    break;
                }
            }
        }
    }
}

try {
    $preview_ac_code = next_customer_code($pdo, $company_code);
} catch (PDOException $e) {
    error_log('Customer code preview error: ' . $e->getMessage());
    $preview_ac_code = '';
}

$keep = ($message_type === 'error');
function keep_val($posted, $col, $keep)
{
    return $keep ? ($posted[$col] ?? '') : '';
}

/** Render one field for either the create form or the detail window. */
function render_field($f, $value, $service_locations, $mode)
{
    $id       = ($mode === 'detail' ? 'd_' : '') . $f['col'];
    $name     = $f['col'];
    $req      = !empty($f['required']);
    $hook     = ($mode === 'detail')
              ? ' data-f="' . htmlspecialchars($f['col']) . '"' . ($req ? ' data-required' : '') . ' disabled'
              : '';
    $reqAttr  = ($req && $mode === 'create') ? ' required' : '';
    $cls      = 'input-shadow';
    if (!empty($f['class'])) {
        $cls .= ' ' . $f['class'];
    }

    $wrapCls = 'cf-field';
    if (!empty($f['span']) && (int) $f['span'] === 2) {
        $wrapCls .= ' cf-field--span2';
    }
    echo '<div class="' . $wrapCls . '">';
    echo '<label for="' . htmlspecialchars($id) . '" class="block">'
       . htmlspecialchars($f['label']);
    if ($req) {
        echo ' <span class="text-red-500">*</span>';
    }
    echo '</label>';

    if ($f['type'] === 'checkbox') {
        $checked = ($mode === 'create')
                 ? ($value === '' || $value === 'Y')
                 : ($value === 'Y');
        echo '<label class="cf-check">'
           . '<input type="checkbox" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"'
           . ' value="Y"' . ($checked ? ' checked' : '') . $hook . '>'
           . '<span>Customer is active</span>'
           . '</label>';
        echo '</div>';
        return;
    }

    if ($f['type'] === 'select') {
        echo '<select id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"'
           . $reqAttr . $hook . ' class="' . $cls . '">';
        echo '<option value="">-- None --</option>';
        foreach ($service_locations as $loc) {
            $code = $loc['service_location_code'];
            $sel  = ((string) $value === (string) $code) ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($code) . '"' . $sel . '>'
               . htmlspecialchars($loc['service_location_name']) . ' (' . htmlspecialchars($code) . ')</option>';
        }
        echo '</select>';
    } elseif ($f['type'] === 'textarea') {
        echo '<textarea id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '" rows="2"'
           . ' maxlength="' . (int) $f['max'] . '"' . $reqAttr . $hook . ' class="' . $cls . '">'
           . htmlspecialchars($value) . '</textarea>';
    } else {
        echo '<input type="text" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"'
           . ' maxlength="' . (int) $f['max'] . '" value="' . htmlspecialchars($value) . '"'
           . $reqAttr . $hook . ' class="' . $cls . '">';
    }

    echo '</div>';
}

$self_js = json_encode($self_url, JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Customer — INFOS ERP</title>
    <?php include __DIR__ . '/../../includes/erp_styles.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="<?php echo htmlspecialchars(erp_asset_v('assets/css/create_customer.css'), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
</head>
<body class="bg-gray-100 p-3 lg:p-5 erp-body">
    <div class="form-card mx-auto bg-white rounded-lg shadow-lg">
        <div class="cf-header">
            <h2><i class="fa fa-user-circle" aria-hidden="true"></i> Create Customer</h2>
            <button type="button" class="lookup-btn" id="openLookup" title="Search existing customers">
                <i class="fa fa-search"></i> Search
            </button>
        </div>

        <?php if ($message !== '' && $message_type === 'success'): ?>
            <div class="cf-toast" id="cfToast" role="status">
                <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php elseif ($message !== ''): ?>
            <div class="cf-alert bg-red-100 text-red-700">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form action="" method="post" autocomplete="off">

            <div class="cf-grid cf-grid-id">
                <div class="cf-field">
                    <label for="customer_code" class="block">Customer Code</label>
                    <input type="text" id="customer_code" readonly
                           value="<?php echo $preview_ac_code !== '' ? htmlspecialchars($preview_ac_code) : '—'; ?>"
                           class="input-shadow">
                </div>
                <div class="cf-field">
                    <label for="description" class="block">
                        Customer Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="description" name="description" required
                           maxlength="<?php echo CUST_NAME_MAX; ?>"
                           value="<?php echo htmlspecialchars(keep_val($posted, 'description', $keep)); ?>"
                           class="input-shadow"
                           placeholder="Enter customer name">
                </div>
                <?php foreach ($FIELDS as $f): ?>
                    <?php if ($f['section'] !== 'identity') { continue; } ?>
                    <?php render_field($f, keep_val($posted, $f['col'], $keep), $service_locations, 'create'); ?>
                <?php endforeach; ?>
            </div>

            <?php foreach ($SECTIONS as $key => $title): ?>
                <div class="cf-section">
                    <h3><?php echo htmlspecialchars($title); ?></h3>
                    <div class="cf-grid">
                        <?php foreach ($FIELDS as $f): ?>
                            <?php if ($f['section'] !== $key) { continue; } ?>
                            <?php render_field($f, keep_val($posted, $f['col'], $keep), $service_locations, 'create'); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="cf-actions">
                <span class="cf-actions-hint">Fields marked <span class="text-red-500">*</span> are required.</span>
                <button type="submit" class="cf-submit">
                    <i class="fa fa-save"></i> Create Customer
                </button>
            </div>
        </form>
    </div>

    <!-- Existing-customer lookup -->
    <div class="lookup-overlay" id="lookupOverlay">
        <div class="lookup-modal">
            <div class="lookup-head">
                <h3><i class="fa fa-search"></i> Search Existing Customers</h3>
                <button type="button" class="lookup-close" id="closeLookup" aria-label="Close">&times;</button>
            </div>
            <div class="lookup-body">
                <input type="text" id="lookupInput" class="lookup-search"
                       placeholder="Search by customer name, code, contact person or mobile...">
                <div class="lookup-scopebar">
                    <div class="lookup-status" id="lookupStatus" style="margin:0;">Type to search, or browse the first results below.</div>
                    <button type="button" class="lookup-reset" id="lookupReset" hidden>All fields</button>
                </div>
                <div class="lookup-results">
                    <table>
                        <thead>
                            <tr id="lookupHead">
                                <th style="width:90px;"  data-field="ac_code"      title="Search Code only">Code</th>
                                <th                      data-field="description"  title="Search Customer Name only">Customer Name</th>
                                <th style="width:170px;" data-field="contact_name" title="Search Contact Person only">Contact Person</th>
                                <th style="width:140px;" data-field="contact_no"   title="Search Mobile No only">Mobile No</th>
                            </tr>
                        </thead>
                        <tbody id="lookupRows">
                            <tr><td colspan="4" class="lookup-empty">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer detail / edit -->
    <div class="lookup-overlay detail-overlay" id="detailOverlay">
        <div class="lookup-modal detail-modal">
            <div class="lookup-head">
                <h3><i class="fa fa-id-card"></i> Customer Details</h3>
                <button type="button" class="lookup-close" id="closeDetail" aria-label="Close">&times;</button>
            </div>
            <div class="lookup-body">
                <div class="detail-editing-note" id="detailEditNote" hidden>
                    <i class="fa fa-pencil"></i> Editing — Customer Code cannot be changed.
                </div>

                <div class="cf-grid cf-grid-id">
                    <div class="cf-field">
                        <label class="block">Customer Code</label>
                        <input type="text" id="d_ac_code" disabled class="input-shadow">
                    </div>
                    <div class="cf-field">
                        <label for="d_description" class="block">
                            Customer Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="d_description" data-f="description" data-required disabled
                               maxlength="<?php echo CUST_NAME_MAX; ?>"
                               class="input-shadow">
                    </div>
                    <?php foreach ($FIELDS as $f): ?>
                        <?php if ($f['section'] !== 'identity') { continue; } ?>
                        <?php render_field($f, '', $service_locations, 'detail'); ?>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($SECTIONS as $key => $title): ?>
                    <div class="cf-section">
                        <h3><?php echo htmlspecialchars($title); ?></h3>
                        <div class="cf-grid">
                            <?php foreach ($FIELDS as $f): ?>
                                <?php if ($f['section'] !== $key) { continue; } ?>
                                <?php render_field($f, '', $service_locations, 'detail'); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="detail-foot">
                <div class="detail-foot-left">
                    <?php if ($can_delete): ?>
                    <button type="button" class="btn btn-danger" id="detailDelete">
                        <i class="fa fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                    <span class="detail-msg" id="detailMsg"></span>
                </div>
                <div>
                    <button type="button" class="btn btn-ghost" id="detailCancel" hidden>Cancel</button>
                    <button type="button" class="btn btn-ghost" id="detailNew" title="Open a blank Create Customer form">
                        <i class="fa fa-plus"></i> New
                    </button>
                    <button type="button" class="btn btn-primary" id="detailEdit">
                        <i class="fa fa-pencil"></i> Edit
                    </button>
                    <button type="button" class="btn btn-primary" id="detailSave" hidden>
                        <i class="fa fa-save"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

<script>
(function () {
    var SELF = <?php echo $self_js; ?>;
    function cusUrl(qs) {
        return SELF + (SELF.indexOf('?') >= 0 ? '&' : '?') + qs;
    }
    var overlay = document.getElementById('lookupOverlay');
    var input   = document.getElementById('lookupInput');
    var rows    = document.getElementById('lookupRows');
    var status  = document.getElementById('lookupStatus');
    var head    = document.getElementById('lookupHead');
    var reset   = document.getElementById('lookupReset');
    var timer   = null;
    var seq     = 0;
    var field   = 'all';

    var dOverlay = document.getElementById('detailOverlay');
    var dMsg     = document.getElementById('detailMsg');
    var dNote    = document.getElementById('detailEditNote');
    var bEdit    = document.getElementById('detailEdit');
    var bSave    = document.getElementById('detailSave');
    var bCancel  = document.getElementById('detailCancel');
    var bDelete  = document.getElementById('detailDelete') || document.createElement('button');
    var bNew     = document.getElementById('detailNew');
    var current  = null;

    var LABELS = {
        all: 'All fields', ac_code: 'Code', description: 'Customer Name',
        contact_name: 'Contact Person', contact_no: 'Mobile No'
    };
    var PLACEHOLDERS = {
        all: 'Search by customer name, code, contact person or mobile...',
        ac_code: 'Search by customer code...',
        description: 'Search by customer name...',
        contact_name: 'Search by contact person...',
        contact_no: 'Search by mobile number...'
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function dash(s) {
        return (s === '' || s == null) ? '<span class="lookup-muted">&mdash;</span>' : esc(s);
    }
    function applyScope() {
        Array.prototype.forEach.call(head.querySelectorAll('th[data-field]'), function (th) {
            th.classList.toggle('active', th.getAttribute('data-field') === field);
        });
        reset.hidden = (field === 'all');
        input.placeholder = PLACEHOLDERS[field] || PLACEHOLDERS.all;
    }
    function scopeSuffix() {
        return field === 'all' ? '' : ' (in ' + (LABELS[field] || field) + ')';
    }

    function search(term) {
        var mine = ++seq;
        status.textContent = 'Searching...';
        fetch(cusUrl('action=search&q=' + encodeURIComponent(term) + '&field=' + encodeURIComponent(field)),
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (mine !== seq) { return; }
                if (!res.success) {
                    status.textContent = res.message || 'Search failed.';
                    rows.innerHTML = '<tr><td colspan="4" class="lookup-empty">Search failed.</td></tr>';
                    return;
                }
                if (!res.data.length) {
                    status.textContent = 'No customers matched' + scopeSuffix() + '.';
                    rows.innerHTML = '<tr><td colspan="4" class="lookup-empty">No matching customers.</td></tr>';
                    return;
                }
                status.textContent = (res.truncated
                    ? ('Showing the first ' + res.limit + ' matches' + scopeSuffix() + ' - refine your search. ')
                    : (res.count + ' customer' + (res.count === 1 ? '' : 's') + ' found' + scopeSuffix() + '. '))
                    + 'Click a row for full details.';
                rows.innerHTML = res.data.map(function (c) {
                    return '<tr data-code="' + esc(c.ac_code) + '">'
                         + '<td>' + esc(c.ac_code) + '</td>'
                         + '<td>' + dash(c.description) + '</td>'
                         + '<td>' + dash(c.contact_name) + '</td>'
                         + '<td>' + dash(c.contact_no) + '</td>'
                         + '</tr>';
                }).join('');
            })
            .catch(function () {
                if (mine !== seq) { return; }
                status.textContent = 'Search failed.';
                rows.innerHTML = '<tr><td colspan="4" class="lookup-empty">Search failed.</td></tr>';
            });
    }

    function run() { search(input.value.trim()); }
    function open() { overlay.classList.add('open'); input.focus(); run(); }
    function close() { overlay.classList.remove('open'); }

    document.getElementById('openLookup').addEventListener('click', open);
    document.getElementById('closeLookup').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });

    head.addEventListener('click', function (e) {
        var th = e.target.closest('th[data-field]');
        if (!th) { return; }
        var picked = th.getAttribute('data-field');
        field = (field === picked) ? 'all' : picked;
        applyScope();
        input.focus();
        run();
    });
    reset.addEventListener('click', function () {
        field = 'all'; applyScope(); input.focus(); run();
    });

    function detailInputs() { return dOverlay.querySelectorAll('[data-f]'); }
    function setEditing(on) {
        Array.prototype.forEach.call(detailInputs(), function (el) { el.disabled = !on; });
        dNote.hidden = !on;
        bEdit.hidden = on;
        bSave.hidden = !on;
        bCancel.hidden = on ? false : true;
        bDelete.hidden = on;
        bNew.hidden = on;
    }
    function fill(data) {
        document.getElementById('d_ac_code').value = data.ac_code || '';
        Array.prototype.forEach.call(detailInputs(), function (el) {
            var k = el.getAttribute('data-f');
            var v = (data[k] == null) ? '' : data[k];
            if (el.type === 'checkbox') { el.checked = (v === 'Y'); }
            else { el.value = v; }
        });
    }
    function openDetail(code) {
        dMsg.textContent = '';
        dMsg.className = 'detail-msg';
        setEditing(false);
        dOverlay.classList.add('open');
        fill({ ac_code: code });
        dMsg.textContent = 'Loading...';
        fetch(cusUrl('action=search&ac_code=' + encodeURIComponent(code)), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    dMsg.textContent = res.message || 'Could not load this customer.';
                    dMsg.className = 'detail-msg err';
                    bEdit.disabled = true;
                    bDelete.disabled = true;
                    return;
                }
                current = res.data;
                fill(current);
                bEdit.disabled = false;
                bDelete.disabled = false;
                dMsg.textContent = '';
            })
            .catch(function () {
                dMsg.textContent = 'Could not load this customer.';
                dMsg.className = 'detail-msg err';
                bEdit.disabled = true;
                bDelete.disabled = true;
            });
    }
    function closeDetail() {
        dOverlay.classList.remove('open');
        setEditing(false);
    }

    rows.addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-code]');
        if (tr) { openDetail(tr.getAttribute('data-code')); }
    });
    document.getElementById('closeDetail').addEventListener('click', closeDetail);
    dOverlay.addEventListener('click', function (e) {
        if (e.target === dOverlay) { closeDetail(); }
    });

    bNew.addEventListener('click', function () { window.location.href = SELF; });
    bEdit.addEventListener('click', function () {
        dMsg.textContent = ''; dMsg.className = 'detail-msg'; setEditing(true);
    });
    bCancel.addEventListener('click', function () {
        if (current) { fill(current); }
        dMsg.textContent = ''; dMsg.className = 'detail-msg'; setEditing(false);
    });

    bSave.addEventListener('click', function () {
        var payload = new FormData();
        payload.append('ac_code', document.getElementById('d_ac_code').value);
        var missing = null;
        Array.prototype.forEach.call(detailInputs(), function (el) {
            if (el.type === 'checkbox') {
                payload.append(el.getAttribute('data-f'), el.checked ? 'Y' : 'N');
                return;
            }
            var v = (el.value || '').trim();
            payload.append(el.getAttribute('data-f'), v);
            if (!missing && el.hasAttribute('data-required') && v === '') { missing = el; }
        });
        if (missing) {
            var lbl = missing.previousElementSibling ? missing.previousElementSibling.textContent : 'A required field';
            dMsg.textContent = lbl.replace('*', '').trim() + ' is required.';
            dMsg.className = 'detail-msg err';
            missing.focus();
            return;
        }
        if (!window.confirm('Save these changes to customer ' + document.getElementById('d_ac_code').value + '?')) {
            return;
        }
        bSave.disabled = true;
        bCancel.disabled = true;
        dMsg.textContent = 'Saving...';
        dMsg.className = 'detail-msg';
        fetch(cusUrl('action=update'), { method: 'POST', credentials: 'same-origin', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                bSave.disabled = false;
                bCancel.disabled = false;
                if (!res.success) {
                    dMsg.textContent = res.message || 'Save failed.';
                    dMsg.className = 'detail-msg err';
                    return;
                }
                current = res.data;
                fill(current);
                setEditing(false);
                dMsg.textContent = 'Changes saved.';
                dMsg.className = 'detail-msg ok';
                run();
            })
            .catch(function () {
                bSave.disabled = false;
                bCancel.disabled = false;
                dMsg.textContent = 'Save failed.';
                dMsg.className = 'detail-msg err';
            });
    });

    bDelete.addEventListener('click', function () {
        var code = document.getElementById('d_ac_code').value;
        var name = document.getElementById('d_description').value || '';
        if (!code) { return; }
        if (!window.confirm('Delete customer ' + code + (name ? ' (' + name + ')' : '') + '?\n\nThis cannot be undone.')) {
            return;
        }
        var payload = new FormData();
        payload.append('ac_code', code);
        bDelete.disabled = true;
        bEdit.disabled = true;
        dMsg.textContent = 'Deleting...';
        dMsg.className = 'detail-msg';
        fetch(cusUrl('action=delete'), { method: 'POST', credentials: 'same-origin', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                bDelete.disabled = false;
                bEdit.disabled = false;
                if (!res.success) {
                    dMsg.textContent = res.message || 'Delete failed.';
                    dMsg.className = 'detail-msg err';
                    return;
                }
                closeDetail();
                status.textContent = res.message;
                run();
            })
            .catch(function () {
                bDelete.disabled = false;
                bEdit.disabled = false;
                dMsg.textContent = 'Delete failed.';
                dMsg.className = 'detail-msg err';
            });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (dOverlay.classList.contains('open')) { closeDetail(); return; }
        if (overlay.classList.contains('open')) { close(); }
    });

    applyScope();

    var toast = document.getElementById('cfToast');
    if (toast) {
        setTimeout(function () {
            toast.classList.add('cf-toast-hide');
            setTimeout(function () { toast.remove(); }, 400);
        }, 3000);
    }
})();
</script>
</body>
</html>
