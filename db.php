<?php
@session_start();

$configFile = __DIR__ . '/config.local.php';
if (!is_readable($configFile)) {
	$configFile = __DIR__ . '/config.local.example.php';
}
$erpConfig = is_readable($configFile) ? (require $configFile) : [];
if (!is_array($erpConfig)) {
	$erpConfig = [];
}

// Web root for static assets (use '/' when app is at site root, e.g. http://host:8050/)
if (!defined('ERP_WEB_ROOT')) {
	define('ERP_WEB_ROOT', $erpConfig['erp_web_root'] ?? '/');
}

$server   = $erpConfig['db_server']   ?? 'localhost';
$database = $erpConfig['db_database'] ?? '';
$trustCert = !empty($erpConfig['db_trust_server_certificate']);

// App login uses branch_user.login_password. SQL Server must use a service account
// from config.local.php (db_username / db_password). Session fallback is legacy only.
$configUser = trim((string) ($erpConfig['db_username'] ?? ''));
$configPass = (string) ($erpConfig['db_password'] ?? '');
$user     = $configUser !== '' ? $configUser : trim((string) ($_SESSION['dbusername'] ?? ''));
$password = $configUser !== '' ? $configPass : (string) ($_SESSION['dbpwd'] ?? '');

if ($configUser === '' && $user === '') {
	error_log('db.php: set db_username / db_password in config.local.php (SQL service account).');
}

$tz = $erpConfig['timezone'] ?? 'Asia/Dubai';
date_default_timezone_set($tz);

try {
	// PDO Connection string for standard Microsoft SQL Server Driver (sqlsrv)
	$dsn = "sqlsrv:Server=$server;Database=$database";
	if ($trustCert) {
		$dsn .= ';TrustServerCertificate=true';
	}
	$con = new PDO($dsn, $user, $password);

	// Throw standard PHP Exception when database error occurs
	$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	// Fetch objects efficiently as arrays
	$con->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
	// Log error securely
	error_log("Connection failed: " . $e->getMessage());

	if (defined('DO_SEND_JSON_API') && DO_SEND_JSON_API) {
		if (!headers_sent()) {
			http_response_code(500);
			header('Content-Type: application/json; charset=utf-8');
		}
		echo json_encode([
			'success' => false,
			'message' => 'Database connection failed. Please log in again.',
		], JSON_UNESCAPED_UNICODE);
		exit;
	}

	$s = "F";
	require_once __DIR__ . '/includes/erp_paths.php';
	echo "<div class='form'><h3>Database Connection Error</h3>";
	echo "<p><b>Error:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
	echo "<p><b>Code:</b> " . $e->getCode() . "</p>";
	echo "<br/>Click here to <a href='" . htmlspecialchars(erp_url('login'), ENT_QUOTES, 'UTF-8') . "'>Login</a></div>";
	die();
}
?>
