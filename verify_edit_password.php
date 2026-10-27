<?php
session_start();
error_reporting(0); // Suppress warnings

require('db.php');

// Clean buffer
ob_clean();

header('Content-Type: application/json; charset=utf-8');

$password = isset($_POST['password']) ? $_POST['password'] : '';
$company_code = isset($_SESSION['company_code']) ? $_SESSION['company_code'] : '';
$branch_code = isset($_SESSION['branch_code']) ? $_SESSION['branch_code'] : '';
$user = isset($_SESSION['username']) ? $_SESSION['username'] : '';

if (!$password || !$user) {
    echo json_encode(['success' => false, 'message' => 'Missing password or user session']);
    exit;
}

$sql = "SELECT edit_password,
               ISNULL(allow_edit, 'N') AS allow_edit,
               ISNULL(allow_delete, 'N') AS allow_delete
        FROM branch_user
        WHERE branch_code = ?
          AND user_id = ?
          AND user_type = 'A'";

$stmt = $con->prepare($sql);
$stmt->execute(array($branch_code, $user));
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && isset($row['edit_password'])) {
    $db_pwd = trim($row['edit_password']);
    if ($db_pwd === trim($password)) {
        echo json_encode([
            'success'      => true,
            'allow_edit'   => strtoupper(trim((string) ($row['allow_edit'] ?? 'N'))),
            'allow_delete' => strtoupper(trim((string) ($row['allow_delete'] ?? 'N'))),
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid password']);
?>