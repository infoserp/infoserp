<?php
require('db.php');
include('auth.php');

if (!isset($_GET['doc_no'], $_GET['sl'])) {
    die('Invalid request');
}

$doc_no = $_GET['doc_no'];
$sl     = (int)$_GET['sl'];

$sql = "
    SELECT file_name, file_path
    FROM doc_attachment
    WHERE doc_no = ? AND serial_no = ?
";

$stmt = $con->prepare($sql);
$stmt->execute(array($doc_no, $sl));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die('Attachment not found');
}

$file_name = $row['file_name'];
$file_path = $row['file_path'];

/* Build full path (supports local + UNC path) */
$fullPath = rtrim($file_path, '\\/') . DIRECTORY_SEPARATOR . $file_name;

if (!file_exists($fullPath)) {
    die('File not found on server');
}

/* Force inline PDF view */
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($file_name) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Accept-Ranges: bytes');

readfile($fullPath);
exit;
?>
