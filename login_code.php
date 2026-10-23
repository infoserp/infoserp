<?php
function customError($errno, $errstr)
{
	echo "<b>Error:</b> [$errstr]";
	echo "<b>[$errno]</b>";
}

require('db_common.php');
require_once __DIR__ . '/includes/erp_paths.php';

if (isset($_POST['username'])) {
	// include("login_common_code.php");
	if ($found == "true") {
		$username = stripslashes($_POST['username']);
		$password = stripslashes($_POST['password']);

		$configFile = __DIR__ . '/config.local.php';
		if (!is_readable($configFile)) {
			$configFile = __DIR__ . '/config.local.example.php';
		}
		$erpConfig = is_readable($configFile) ? (require $configFile) : [];
		if (!is_array($erpConfig)) {
			$erpConfig = [];
		}

		$db_server = $erpConfig['db_server'] ?? '';
		$db_name   = $erpConfig['db_database'] ?? '';

		set_error_handler('customError');
		$con = odbc_connect(
			"Driver={SQL Server Native Client 11.0};Server=$db_server;Database=$db_name;",
			$username,
			$password
		);
		if ($con) {
			$_SESSION['username'] = $username;
			$_SESSION['db_pwd'] = $password;
			erp_redirect('branchselection');
		} else {
			echo "<h3>Username/password is incorrect.</h3>  Click here to<a href='"
				. htmlspecialchars(erp_url('login'), ENT_QUOTES, 'UTF-8')
				. "'><b> Login</b></a>";
		}
	}
} else {
	erp_redirect('login');
}
?>
