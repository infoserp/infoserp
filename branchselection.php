<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="">
<meta name="author" content="">
<title>Infos ERP — Branch Selection</title>
<?php
require_once __DIR__ . '/includes/erp_paths.php';
include __DIR__ . '/includes/erp_styles.php';
$branchAssetsVer = '20261016';
?>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?php echo htmlspecialchars(erp_asset('assets/css/branchselection.css'), ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo $branchAssetsVer; ?>" rel="stylesheet">
</head>
<body class="branch-body">
<?php

require('db.php');
require('auth.php');
require_once __DIR__ . '/includes/erp_user_access.php';

$username = trim((string) ($_SESSION['username'] ?? ''));
if ($username !== '') {
    $_SESSION['username'] = $username;
}
$accessError = '';

$userAccess = erp_load_user_company_branches($con, $username);
$companies  = $userAccess['companies'] ?? [];

if (isset($_POST['branch_code'])) {
    $branch_code = trim((string) ($_POST['branch_code'] ?? ''));
    $accessRow   = erp_user_can_access_branch($con, $username, $branch_code);

    if (!$accessRow) {
        $accessError = 'You do not have access to the selected branch.';
    } else {
        $company_code = $accessRow['company_code'];
        $branch_code  = $accessRow['branch_code'];

        // Preserve existing branch_name display format: "Company, Branch"
        $branchLabel = $accessRow['company_name'];
        if ($accessRow['company_name'] !== '' && $accessRow['branch_name'] !== '') {
            $branchLabel .= ', ' . $accessRow['branch_name'];
        } elseif ($accessRow['branch_name'] !== '') {
            $branchLabel = $accessRow['branch_name'];
        }

        $_SESSION['company_code']  = $company_code;
        $_SESSION['branch_code']   = $branch_code;
        $_SESSION['branch_name']   = $branchLabel;
        $_SESSION['company_name']  = $accessRow['company_name'];

        $_SESSION['allow_dashboard'] = "N";
        $_SESSION['message_sales'] = "N";

        $QUERY = "SELECT allow_dashboard, message_sales
                  FROM branch_user
                  WHERE branch_code  = ?
                    AND user_id      = ?";
        $result = $con->prepare($QUERY);
        $result->execute(array($branch_code, $username));
        $bu_rec = $result->fetch();
        if ($bu_rec) {
            // Normalize to uppercase 'Y' / 'N'
            $_SESSION['allow_dashboard'] = strtoupper(trim($bu_rec['allow_dashboard'])) == 'Y' ? 'Y' : 'N';
            $_SESSION['message_sales'] = strtoupper(trim($bu_rec['message_sales'])) == 'Y' ? 'Y' : 'N';
        }

        $QUERY = "SELECT        sor_confirm_required, lpo_confirm_required
        FROM            control_table WHERE company_code = ? AND branch_code = ?";
        $result = $con->prepare($QUERY);
        $result->execute(array($company_code, $branch_code));
        $records = $result->fetch();
        if ($records) {
            $_SESSION['sor_confirm_required'] = strtoupper($records["sor_confirm_required"]);
            $_SESSION['lpo_confirm_required'] = strtoupper($records["lpo_confirm_required"]);
        }

        $REPORT_QUERY_RESULT = erp_load_active_user_menus($con, $company_code, $branch_code, $username, [
            'm_materialrequestapproval',
            'm_txn_purchaseorderapproval',
            'm_purchaserequestapproval',
            'm_txn_salesquotationapproval',
            'm_salesorderapproval',
            'm_proformainvoiceapproval',
            'm_deliveryrequestapproval',
            'm_salesorderconfirmation',
            'm_txn_purchaseorderconfirmation',
            'm_materialrequest',
            'm_txn_salesquotation',
            'm_txn_jobcard',
            'm_txn_salesinvoice',
            'm_documentsetup',
            'm_changepassword',
            'm_ma-customer',
            'm_tr-receiptvoucher',
            'm_deliveryorderdespatch',
            'm_tr-stocktransferout',
            'm_tr-stocktransferin',
        ]);
        $_SESSION["MR"] = "N";
        $_SESSION["PR"] = "N";
        $_SESSION["PO"] = "N";
        $_SESSION["QT"] = "N";
        $_SESSION["SO"] = "N";
        $_SESSION["PI"] = "N";
        $_SESSION["DR"] = "N";
        $_SESSION["POC"] = "N";
        $_SESSION["SOC"] = "N";
        $_SESSION["MRQ"] = "N";
        $_SESSION["SQ"] = "N";
        $_SESSION["JC"] = "N";
        $_SESSION["SI"] = "N";
        $_SESSION["DOC"] = "N";
        $_SESSION["CPW"] = "N";
        $_SESSION["CUS"] = "N";
        $_SESSION["RV"] = "N";
        $_SESSION["DD"] = "N";
        $_SESSION["TO"] = "N";
        $_SESSION["TI"] = "N";
        $_SESSION["icon_MR"]  = "N";
        $_SESSION["icon_PR"]  = "N";
        $_SESSION["icon_PO"]  = "N";
        $_SESSION["icon_QT"]  = "N";
        $_SESSION["icon_SO"]  = "N";
        $_SESSION["icon_PI"]  = "N";
        $_SESSION["icon_DR"]  = "N";
        $_SESSION["icon_SOC"] = "N";
        $_SESSION["icon_MRQ"] = "N";
        $_SESSION["icon_SQ"]  = "N";
        $_SESSION["icon_JC"]  = "N";
        $_SESSION["icon_SI"]  = "N";
        $_SESSION["icon_DOC"] = "N";
        $_SESSION["icon_CPW"] = "N";
        $_SESSION["icon_CUS"] = "N";
        $_SESSION["icon_RV"]  = "N";
        $_SESSION["icon_DD"]  = "N";
        $_SESSION["icon_TO"]  = "N";
        $_SESSION["icon_TI"]  = "N";
        $_SESSION["menu_paths"] = [];
        foreach ($REPORT_QUERY_RESULT as $REPORT) {
            $_SESSION["menu_paths"][$REPORT["menu_code"]] = [
                'path'       => $REPORT['path'],
                'sort_order' => $REPORT['sort_order'],
            ];
            $menu_icon = (strtoupper(trim((string) ($REPORT['icon'] ?? ''))) === 'Y') ? 'Y' : 'N';
            switch ($REPORT["menu_code"]) {
                case "m_materialrequestapproval": {
                        $_SESSION["MR"] = "Y";
                        $_SESSION["icon_MR"] = $menu_icon;
                        break;
                    }
                case "m_txn_purchaseorderapproval": {
                        $_SESSION["PO"] = "Y";
                        $_SESSION["icon_PO"] = $menu_icon;
                        break;
                    }
                case "m_txn_salesquotationapproval": {
                        $_SESSION["QT"] = "Y";
                        $_SESSION["icon_QT"] = $menu_icon;
                        break;
                    }
                case "m_salesorderapproval": {
                        $_SESSION["SO"] = "Y";
                        $_SESSION["icon_SO"] = $menu_icon;
                        break;
                    }
                case "m_proformainvoiceapproval": {
                        $_SESSION["PI"] = "Y";
                        $_SESSION["icon_PI"] = $menu_icon;
                        break;
                    }
                case "m_deliveryrequestapproval": {
                        $_SESSION["DR"] = "Y";
                        $_SESSION["icon_DR"] = $menu_icon;
                        break;
                    }
                case "m_purchaserequestapproval": {
                        $_SESSION["PR"] = "Y";
                        $_SESSION["icon_PR"] = $menu_icon;
                        break;
                    }
                case "m_salesorderconfirmation": {
                        $_SESSION["SOC"] = "Y";
                        $_SESSION["icon_SOC"] = $menu_icon;
                        break;
                    }
                case "m_txn_purchaseorderconfirmation": {
                        $_SESSION["POC"] = "Y";
                        break;
                    }
                case "m_materialrequest": {
                        $_SESSION["MRQ"] = "Y";
                        $_SESSION["icon_MRQ"] = $menu_icon;
                        break;
                    }
                case "m_txn_salesquotation": {
                        $_SESSION["SQ"] = "Y";
                        $_SESSION["icon_SQ"] = $menu_icon;
                        break;
                    }
                case "m_txn_jobcard": {
                        $_SESSION["JC"] = "Y";
                        $_SESSION["icon_JC"] = $menu_icon;
                        break;
                    }
                case "m_txn_salesinvoice": {
                        $_SESSION["SI"] = "Y";
                        $_SESSION["icon_SI"] = $menu_icon;
                        break;
                    }
                case "m_documentsetup": {
                        $_SESSION["DOC"] = "Y";
                        $_SESSION["icon_DOC"] = $menu_icon;
                        break;
                    }
                case "m_changepassword": {
                        $_SESSION["CPW"] = "Y";
                        $_SESSION["icon_CPW"] = $menu_icon;
                        break;
                    }
                case "m_ma-customer": {
                        $_SESSION["CUS"] = "Y";
                        $_SESSION["icon_CUS"] = $menu_icon;
                        break;
                    }
                case "m_tr-receiptvoucher": {
                        $_SESSION["RV"] = "Y";
                        $_SESSION["icon_RV"] = $menu_icon;
                        break;
                    }
                case "m_deliveryorderdespatch": {
                    $_SESSION["DD"] = "Y";
                    $_SESSION["icon_DD"] = $menu_icon;
                    break;
                }
                case "m_tr-stocktransferout": {
                    $_SESSION["TO"] = "Y";
                    $_SESSION["icon_TO"] = $menu_icon;
                    break;
                }
                case "m_tr-stocktransferin": {
                    $_SESSION["TI"] = "Y";
                    $_SESSION["icon_TI"] = $menu_icon;
                    break;
                }
            }
        }
        header("Location: " . erp_url('home'));
        exit;
    }
}

?>
<?php
$displayName = $username !== '' ? strtoupper($username) : 'USER';
$todayLabel  = date('l, F j, Y');

$companyTotal = count($companies);
$branchTotal  = 0;
foreach ($companies as $c) {
    $branchTotal += count($c['branches']);
}
$panelSizeClass = $companyTotal <= 1 ? 'branch-main--one' : ($companyTotal === 2 ? 'branch-main--two' : '');
?>
<div class="branch-page">

  <header class="branch-hero">
    <div class="branch-hero__inner">
      <div class="branch-hero__top">
        <div class="branch-hero__brand">
          <img src="<?php echo htmlspecialchars(erp_asset('image/infos_logo.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="INFOS ERP" class="branch-hero__logo">
          <span class="branch-hero__brand-name">INFOS ERP</span>
        </div>
        <div class="branch-hero__actions">
          <div class="branch-hero__date">
            <i class="fa fa-calendar" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($todayLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <a href="<?php echo htmlspecialchars(erp_url('logout'), ENT_QUOTES, 'UTF-8'); ?>" class="branch-hero__logout" title="Log out">
            <i class="fa fa-sign-out" aria-hidden="true"></i>
            <span>Log out</span>
          </a>
        </div>
      </div>

      <div class="branch-hero__welcome">
        <h1 class="branch-hero__title">
          Welcome back, <span class="branch-hero__user"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
        </h1>
        <p class="branch-hero__subtitle">Choose your company branch to continue to the accounting &amp; approval workspace.</p>
        <span class="branch-hero__accent" aria-hidden="true"></span>
      </div>
    </div>
  </header>

  <main class="branch-main <?php echo $panelSizeClass; ?>">
    <div class="branch-panel">

      <?php if ($accessError !== ''): ?>
        <div class="branch-alert branch-alert--error" role="alert">
          <i class="fa fa-exclamation-circle" aria-hidden="true"></i>
          <?php echo htmlspecialchars($accessError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <form action="" method="post" name="login" id="branch-select-form">
        <div class="branch-toolbar">
          <div>
            <p class="branch-panel__label">Available Branches</p>
            <?php if ($companyTotal > 0): ?>
              <p class="branch-toolbar__summary">
                <?php echo $companyTotal; ?> compan<?php echo $companyTotal === 1 ? 'y' : 'ies'; ?>
                &middot; <?php echo $branchTotal; ?> branch<?php echo $branchTotal === 1 ? '' : 'es'; ?>
              </p>
            <?php endif; ?>
          </div>
          <?php if ($companyTotal > 3): ?>
            <label class="branch-search">
              <i class="fa fa-search" aria-hidden="true"></i>
              <input type="search" id="branch-search-input" placeholder="Search company or branch..." autocomplete="off" aria-label="Search company or branch">
            </label>
          <?php endif; ?>
        </div>

        <?php if (empty($companies)): ?>
          <div class="branch-empty">
            <i class="fa fa-info-circle" aria-hidden="true"></i>
            No company or branch access is configured for your account.
          </div>
        <?php else: ?>
          <div class="company-grid<?php echo $companyTotal === 1 ? ' company-grid--single' : ''; ?>" id="company-grid">
            <?php foreach ($companies as $company): ?>
              <?php
              $branchCount  = count($company['branches']);
              $companyLabel = $company['company_name'] !== '' ? $company['company_name'] : $company['company_code'];
              ?>
              <section class="company-card" data-search="<?php echo htmlspecialchars(strtolower($companyLabel), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="company-card__head">
                  <span class="company-card__icon" aria-hidden="true"><i class="fa fa-building"></i></span>
                  <div class="company-card__head-text">
                    <h2 class="company-card__title" title="<?php echo htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </h2>
                    <span class="company-card__count"><?php echo $branchCount; ?> branch<?php echo $branchCount === 1 ? '' : 'es'; ?></span>
                  </div>
                </div>

                <div class="company-card__branches">
                  <?php foreach ($company['branches'] as $branch): ?>
                    <?php $branchLabel = $branch['branch_name'] !== '' ? $branch['branch_name'] : $branch['branch_code']; ?>
                    <button type="submit" name="branch_code" class="branch-row"
                      value="<?php echo htmlspecialchars($branch['branch_code'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-search="<?php echo htmlspecialchars(strtolower($branchLabel), ENT_QUOTES, 'UTF-8'); ?>">
                      <span class="branch-row__icon" aria-hidden="true"><i class="fa fa-map-marker"></i></span>
                      <span class="branch-row__body">
                        <span class="branch-row__name"><?php echo htmlspecialchars($branchLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="branch-row__meta">Code <?php echo htmlspecialchars($branch['branch_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                      </span>
                      <span class="branch-row__action">
                        <span class="branch-row__action-label">Enter</span>
                        <i class="fa fa-arrow-right" aria-hidden="true"></i>
                      </span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </div>

          <div class="branch-empty branch-empty--search" id="branch-search-empty" hidden>
            <i class="fa fa-search" aria-hidden="true"></i>
            No company or branch matches your search.
          </div>
        <?php endif; ?>
      </form>
    </div>

    <p class="branch-footer">&copy; INFOS ERP &mdash; Accounting &amp; Approval System</p>
  </main>
</div>

<script src="<?php echo htmlspecialchars(erp_asset('assets/js/branchselection.js'), ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo htmlspecialchars($branchAssetsVer, ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
