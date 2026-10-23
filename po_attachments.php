<?php
require('db.php');
include('auth.php');
require_once __DIR__ . '/includes/erp_paths.php';

erp_guard_standalone_get();

if (!isset($_GET['doc_no']) || $_GET['doc_no'] == '') {
    die('Invalid request');
}

$doc_no = $_GET['doc_no'];
$back_url = erp_iframe_url('approvalline?doc_type=PO');

$sql = "
    SELECT serial_no, file_name
    FROM doc_attachment
    WHERE doc_no = ?
    ORDER BY serial_no
";

$stmt = $con->prepare($sql);
$stmt->execute(array($doc_no));
?>

<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Attachments</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="css/bootstrap.min.css">

    <!-- Font Awesome (optional, if already used) -->
    <link rel="stylesheet" href="css/font-awesome.min.css">

    <style>
        body {
            background: #f8f9fa;
        }
        .card {
            margin-top: 20px;
        }
        .table td, .table th {
            vertical-align: middle;
        }
    </style>
</head>

<body>

<div class="container">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                📎 Attachments for PO: <?php echo htmlspecialchars($doc_no); ?>
            </h5>
            <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-light">
                <i class="fa fa-arrow-left"></i> Back
            </a>
        </div>

        <div class="card-body p-0 table-responsive">
            <table class="table table-striped table-bordered table-hover mb-0">
                <thead class="thead-dark">
                    <tr>
                        <th style="width:10%">#</th>
                        <th>File Name</th>
                        <th style="width:20%" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>

                <?php
                $hasRows = false;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $hasRows = true;

                    // IMPORTANT: Cast serial_no to INT
                    $sl = (int) $row['serial_no'];
                    $file = $row['file_name'];
                ?>
                    <tr>
                        <td><?php echo sprintf('%02d', $sl); ?></td>
                        <td><?php echo htmlspecialchars($file); ?></td>
                        <td class="text-center">
                            <a href="view_po_attachment.php?doc_no=<?php echo urlencode($doc_no); ?>&sl=<?php echo $sl; ?>"
                               target="_blank"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fa fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                <?php } ?>

                <?php if (!$hasRows) { ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted p-3">
                            No attachments found
                        </td>
                    </tr>
                <?php } ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/approvalmenu.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
