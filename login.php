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

    if (isset($_POST['username'])   ){

		$username=$_POST['username'];
		$password=$_POST['password'];
		$username = trim(stripslashes($username));
		$password = stripslashes($password);

		 session_start();
		  $_SESSION["dbusername"]  = $username;
		  $_SESSION["dbpwd"]  = $password ;
		  $s="";
		 include('db.php');

		 if($s<>"F")
		 {
			  $QUERY="SELECT  user_id,   branch_master.company_code 
				 FROM            
										 branch_user   INNER JOIN
										 branch_master ON branch_user.branch_code = branch_master.branch_code
				WHERE        ( user_id = ?)";

				 $result = $con->prepare($QUERY);
				 $result->execute(array($username));
				 $records = $result->fetch();

				if($records)
				{
					 $_SESSION["username"]  = $username;

					require_once __DIR__ . '/includes/erp_paths.php';
					erp_redirect('branchselection');

				 } 
				 else
				 {
					 echo "<div class='login-error-shell min-h-screen flex items-center justify-center p-6'>";
					 echo "<div class='login-card login-alert w-full max-w-md rounded-2xl p-8 text-center'>";
					 echo "<div class='flex justify-center mb-4'><span class='w-12 h-12 rounded-full bg-red-100 flex items-center justify-center'><i class='fa fa-exclamation-circle text-red-500 text-xl' aria-hidden='true'></i></span></div>";
					 echo "<h3 class='text-lg font-bold text-red-800 mb-2'>Username/password is incorrect.</h3>";
					 require_once __DIR__ . '/includes/erp_paths.php';
					 echo "<p class='text-sm text-red-700/80'>Click here to <a href='" . htmlspecialchars(erp_url('login'), ENT_QUOTES, 'UTF-8') . "'>Login</a></p>";
					 echo "</div></div>";
				 }
		}
    }
	else
	{
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
                onkeyup="this.value = this.value.toUpperCase();">
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
