<?php
require_once __DIR__ . '/erp_paths.php';
require_once __DIR__ . '/erp_config.php';
require_once __DIR__ . '/erp_session.php';
$erpSessionLim = erp_session_limits();
$erpSessionWatch = !empty($_SESSION['username']);
?>
<link href="<?php echo htmlspecialchars(erp_font_awesome_href(), ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet" type="text/css">
<?php if ($erpSessionWatch) { ?>
<meta name="erp-session-idle" content="<?php echo (int) $erpSessionLim['idle']; ?>">
<meta name="erp-login-url" content="<?php echo htmlspecialchars(erp_url('login'), ENT_QUOTES, 'UTF-8'); ?>">
<meta name="erp-session-touch" content="<?php echo htmlspecialchars(erp_url('session_touch'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(erp_asset_v('assets/js/erp_session.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php } ?>
