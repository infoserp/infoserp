<?php
/**
 * Login — authenticate against branch_user.login_password (app user),
 * connect to SQL with the service account from config.local.php.
 * POST handling runs before any HTML so erp_redirect() can send Location headers.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function erp_login_error_html($message)
{
    require_once __DIR__ . '/includes/erp_paths.php';
    $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $url = htmlspecialchars(erp_url('login'), ENT_QUOTES, 'UTF-8');
    return "<div class='login-error-shell min-h-screen flex items-center justify-center p-6'>"
        . "<div class='login-card login-alert w-full max-w-md rounded-2xl p-8 text-center'>"
        . "<div class='flex justify-center mb-4'><span class='w-12 h-12 rounded-full bg-red-100 flex items-center justify-center'>"
        . "<i class='fa fa-exclamation-circle text-red-500 text-xl' aria-hidden='true'></i></span></div>"
        . "<h3 class='text-lg font-bold text-red-800 mb-2'>{$msg}</h3>"
        . "<p class='text-sm text-red-700/80'>Click here to <a href='{$url}'>Login</a></p>"
        . "</div></div>";
}

$loginErrorHtml = '';

if (isset($_POST['username'])) {
    $username = strtoupper(trim(stripslashes((string) ($_POST['username'] ?? ''))));
    // Trim — paste from SSMS/email often adds spaces; do not change case of password
    $password = trim((string) ($_POST['password'] ?? ''));

    if ($username === '' || $password === '') {
        $loginErrorHtml = erp_login_error_html('Username and password are required.');
    } else {
        unset($_SESSION['dbusername'], $_SESSION['dbpwd'], $_SESSION['db_pwd']);

        $s = '';
        require __DIR__ . '/db.php';

        if ($s !== 'F' && isset($con) && $con instanceof PDO) {
            require_once __DIR__ . '/includes/erp_auth.php';
            require_once __DIR__ . '/includes/erp_paths.php';
            require_once __DIR__ . '/includes/erp_config.php';

            $erpConfig = erp_load_config();

            $allowedTypes = erp_auth_allowed_user_types($erpConfig);
            $row = erp_auth_find_user($con, $username);

            $ok = false;
            $failMsg = 'Username/password is incorrect.';

            if ($row) {
                $userType = strtoupper(trim((string) ($row['user_type'] ?? '')));
                $storedPwd = trim((string) ($row['login_password'] ?? ''));

                if ($userType === '' || !in_array($userType, $allowedTypes, true)) {
                    $failMsg = 'This user type is not allowed to sign in.';
                } elseif ($storedPwd === '') {
                    $failMsg = 'No login password is set for this user.';
                } elseif (!erp_password_verify($password, $storedPwd)) {
                    $failMsg = 'Username/password is incorrect.';
                } else {
                    $ok = true;
                }
            }

            if ($ok) {
                $_SESSION['username'] = $username;
                $_SESSION['user_type'] = strtoupper(trim((string) $row['user_type']));
                $_SESSION['user_name'] = trim((string) ($row['user_name'] ?? $username));
                $_SESSION['allow_allbranch'] = strtoupper(trim((string) ($row['allow_allbranch'] ?? 'N'))) === 'Y' ? 'Y' : 'N';

                if (!empty($erpConfig['rehash_login_password_on_login'])
                    && erp_password_needs_rehash($row['login_password'] ?? '')) {
                    erp_auth_upgrade_login_password($con, $username, $password);
                }

                try {
                    $con->prepare(
                        "UPDATE branch_user
                         SET login_count = ISNULL(login_count, 0) + 1,
                             last_userid = ?,
                             last_userdate = GETDATE()
                         WHERE user_id = ?"
                    )->execute([$username, $username]);
                } catch (Exception $e) {
                    error_log('login login_count update: ' . $e->getMessage());
                }

                erp_redirect('branchselection');
            }

            $loginErrorHtml = erp_login_error_html($failMsg);
        }
        // If $s === 'F', db.php already printed the connection error and exited.
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="">
<meta name="author" content="">
<title>Infos ERP</title>
<?php include __DIR__ . '/includes/erp_styles.php'; ?>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="assets/css/login.css?v=20261015b" rel="stylesheet">
</head>
<body class="login-body">
<?php
if ($loginErrorHtml !== '') {
    echo $loginErrorHtml;
} else {
?>
<div class="login-shell">

  <aside class="login-visual" aria-hidden="true">
    <img src="image/logo_bg.png" alt="" class="login-visual__image">
    <div class="login-visual__overlay"></div>

    <div class="login-visual__content">
      <div class="login-brand login-brand--on-dark">
        <span class="login-brand__mark">IE</span>
        <span class="login-brand__text">
          <span class="login-brand__name">INFOS ERP</span>
          <span class="login-brand__tag">Accounting &amp; Approval System</span>
        </span>
      </div>

      <div class="login-visual__copy">
        <h2 class="login-visual__headline">One workspace.<br>Complete business control.</h2>
        <p class="login-visual__text">Approvals, accounting, sales and purchasing for every company and branch, in one secure ERP.</p>
        <ul class="login-visual__features">
          <li><i class="fa fa-check-circle"></i> Multi-company &amp; multi-branch access</li>
          <li><i class="fa fa-check-circle"></i> Streamlined approval workflows</li>
          <li><i class="fa fa-check-circle"></i> Role-based menus and permissions</li>
        </ul>
      </div>

      <p class="login-visual__footer">&copy; INFOS ERP</p>
    </div>
  </aside>

  <main class="login-main">
    <div class="login-main__inner">

      <div class="login-brand login-brand--mobile">
        <span class="login-brand__mark">IE</span>
        <span class="login-brand__text">
          <span class="login-brand__name">INFOS ERP</span>
          <span class="login-brand__tag">Accounting &amp; Approval System</span>
        </span>
      </div>

      <div class="login-card">
        <div class="login-card__header">
          <h1 class="login-card__title">Welcome back</h1>
          <p class="login-card__subtitle">Sign in to continue to your ERP dashboard</p>
        </div>

        <form action="" method="post" name="login" autocomplete="off" class="login-form">

          <div class="login-field-group">
            <label for="username" class="login-label">Username</label>
            <div class="login-input-wrap">
              <i class="login-input-icon fa fa-user" aria-hidden="true"></i>
              <input class="login-field"
                placeholder="Enter your username"
                name="username"
                id="username"
                type="text"
                onkeyup="this.value = this.value.toUpperCase();"
                autofocus>
            </div>
          </div>

          <div class="login-field-group">
            <label for="password" class="login-label">Password</label>
            <div class="login-input-wrap">
              <i class="login-input-icon fa fa-lock" aria-hidden="true"></i>
              <input class="login-field"
                placeholder="Enter your password"
                name="password"
                id="password"
                type="password"
                autocomplete="current-password">
              <button type="button" id="toggle-password" class="login-toggle-pwd"
                aria-label="Show password" title="Show password">
                <i class="fa fa-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <button name="submit" type="submit" value="Login" class="login-submit">
            Login
            <i class="fa fa-arrow-right" aria-hidden="true"></i>
          </button>

        </form>
      </div>

      <p class="login-footer">&copy; INFOS ERP &mdash; Accounting &amp; Approval System</p>
    </div>
  </main>

</div>
<?php } ?>
<script src="assets/js/login.js"></script>
</body>
</html>
