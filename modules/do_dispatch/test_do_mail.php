<?php
/**
 * DO Dispatch — Microsoft 365 SMTP test mail.
 * Sends a sample delivery-order template via Microsoft Graph or SMTP.
 *
 * Usage: /modules/do_dispatch/test_do_mail?embed=1
 * Optional: &doc_no=YOUR_DO_NUMBER  (includes real DO details when found)
 */
ob_start();
require('../../db.php');
include('../../auth.php');
require_once __DIR__ . '/../../includes/erp_paths.php';
require_once __DIR__ . '/../../includes/do_mail_config_loader.php';
require_once __DIR__ . '/../../includes/do_mail_sender.php';

erp_guard_standalone_get();

set_time_limit(60);

$mailConfigPath = erp_do_mail_config_path();
$mailConfig     = erp_load_do_mail_config();
$doc_no     = trim($_GET['doc_no'] ?? 'DO-TEST-001');
$toEmail    = trim($_GET['to'] ?? ($mailConfig['test_to'] ?? ''));
$result     = null;
$error      = null;
$mailLog    = [];
$mailDriver = strtolower((string) ($mailConfig['mail_driver'] ?? 'graph'));
$doSummary  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $toEmail = trim($_POST['to_email'] ?? $toEmail);
    $doc_no  = trim($_POST['doc_no'] ?? $doc_no);

    try {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient email address.');
        }

        $company_code = $_SESSION['company_code'];
        $branch_code  = $_SESSION['branch_code'];

        $doSummary = [
            'doc_no'        => $doc_no,
            'doc_dt'        => date('Y-m-d'),
            'customer_name' => 'Test Customer — Fandoes',
            'po_no'         => 'PO-TEST-1001',
            'line_count'    => 2,
        ];

        if ($doc_no !== '' && $doc_no !== 'DO-TEST-001') {
            $stmt = $con->prepare(
                "SELECT dnh.doc_no, dnh.doc_dt, dnh.po_no, am.description AS customer_name,
                        (SELECT COUNT(*) FROM delivery_note_d d
                         WHERE d.doc_no = dnh.doc_no
                           AND d.company_code = dnh.company_code
                           AND d.branch_code = dnh.branch_code) AS line_count
                 FROM delivery_note_h dnh
                 LEFT JOIN ac_master am
                   ON am.ac_code = dnh.ac_code AND am.company_code = dnh.company_code
                 WHERE dnh.doc_no = :doc_no
                   AND dnh.company_code = :company_code
                   AND dnh.branch_code = :branch_code"
            );
            $stmt->execute([
                ':doc_no'        => $doc_no,
                ':company_code'  => $company_code,
                ':branch_code'   => $branch_code,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $doSummary = $row;
            }
        }

        $subject = 'DO Dispatch Test — Delivery Order ' . $doSummary['doc_no'];

        $htmlBody = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,#1e40af,#2563eb);padding:24px 28px;color:#ffffff;">
              <div style="font-size:12px;opacity:0.85;letter-spacing:0.08em;text-transform:uppercase;">Fandoes Deliveries</div>
              <div style="font-size:22px;font-weight:700;margin-top:6px;">Delivery Order Dispatch</div>
              <div style="font-size:13px;margin-top:4px;opacity:0.9;">Test email — printout mail pipeline</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;color:#0f172a;">
              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Hello,</p>
              <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">
                This is a <strong>test message</strong> to confirm that DO dispatch printouts can be sent from
                <strong>' . htmlspecialchars($mailConfig['from_email'], ENT_QUOTES, 'UTF-8') . '</strong>
                via Microsoft Graph mail.
              </p>
              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:20px;">
                <tr>
                  <td style="padding:16px 18px;">
                    <div style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:0.06em;">Delivery Order</div>
                    <div style="font-size:20px;font-weight:700;color:#1e40af;margin-top:4px;">' . htmlspecialchars($doSummary['doc_no'], ENT_QUOTES, 'UTF-8') . '</div>
                    <table width="100%" style="margin-top:14px;font-size:14px;color:#334155;">
                      <tr><td style="padding:4px 0;width:140px;color:#64748b;">Date</td><td>' . htmlspecialchars($doSummary['doc_dt'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td></tr>
                      <tr><td style="padding:4px 0;color:#64748b;">Customer</td><td>' . htmlspecialchars($doSummary['customer_name'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td></tr>
                      <tr><td style="padding:4px 0;color:#64748b;">PO No</td><td>' . htmlspecialchars($doSummary['po_no'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td></tr>
                      <tr><td style="padding:4px 0;color:#64748b;">Line items</td><td>' . htmlspecialchars((string) ($doSummary['line_count'] ?? '0'), ENT_QUOTES, 'UTF-8') . '</td></tr>
                    </table>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#475569;">
                When live, the PDF or HTML printout of the delivery order will be attached here.
              </p>
              <p style="margin:0;font-size:13px;color:#94a3b8;">Sent at ' . date('Y-m-d H:i:s T') . ' by ' . htmlspecialchars($_SESSION['username'] ?? 'system', ENT_QUOTES, 'UTF-8') . '</p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;">
              Fandoes ERP — DO Dispatch mail test. Do not reply to this automated test.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

        $textBody = "DO Dispatch Test\n\n"
            . "Delivery Order: " . $doSummary['doc_no'] . "\n"
            . "Date: " . ($doSummary['doc_dt'] ?? '-') . "\n"
            . "Customer: " . ($doSummary['customer_name'] ?? '-') . "\n"
            . "PO No: " . ($doSummary['po_no'] ?? '-') . "\n\n"
            . "This confirms mail from " . ($mailConfig['graph']['from_email'] ?? $mailConfig['from_email'] ?? '') . " is working.\n";

        $samplePdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

        $sendResult = DoMailSender::send($mailConfig, $toEmail, $subject, $htmlBody, $textBody, [[
            'name' => 'DO_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $doSummary['doc_no']) . '_sample.pdf',
            'mime' => 'application/pdf',
            'data' => $samplePdf,
        ]]);

        $mailLog    = $sendResult['log'];
        $mailDriver = $sendResult['driver'];
        $result     = 'Test email sent successfully to ' . $toEmail . ' via ' . strtoupper($mailDriver) . '. Check inbox and spam folder.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$backUrl = erp_iframe_url('modules/do_dispatch/do_dispatch');
$opensslReady = DoGraphMailer::opensslReady();
$curlReady    = DoGraphMailer::curlReady();
$phpIni       = php_ini_loaded_file() ?: '(unknown)';
$smtpMode     = (($mailConfig['smtp_secure'] ?? 'tls') === 'ssl') ? 'SSL (implicit)' : 'STARTTLS';
$fromEmail    = $mailConfig['graph']['from_email'] ?? $mailConfig['from_email'] ?? '';
$graphReady   = $mailDriver === 'graph'
    && trim((string) ($mailConfig['graph']['tenant_id'] ?? '')) !== ''
    && trim((string) ($mailConfig['graph']['client_id'] ?? '')) !== ''
    && trim((string) ($mailConfig['graph']['client_secret'] ?? '')) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DO Mail Test</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, Segoe UI, Arial, sans-serif; background: #f1f5f9; margin: 0; padding: 24px; color: #0f172a; }
        .wrap { max-width: 720px; margin: 0 auto; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 8px 24px rgba(15,23,42,.08); padding: 28px; }
        h1 { margin: 0 0 8px; font-size: 24px; }
        .sub { color: #64748b; margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #334155; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 16px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: #fff; border: 0; border-radius: 10px; padding: 12px 18px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        .btn-secondary { background: #fff; color: #334155; border: 1px solid #cbd5e1; text-decoration: none; margin-left: 8px; }
        .ok { background: #ecfdf5; border: 1px solid #86efac; color: #166534; padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; }
        .err { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; }
        .info { background: #eff6ff; border: 1px solid #93c5fd; color: #1e3a8a; padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 10px; overflow: auto; font-size: 12px; max-height: 280px; }
        .meta { font-size: 13px; color: #64748b; margin-top: 16px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>DO Dispatch — Mail Test</h1>
        <p class="sub">Send a sample delivery-order email via <?php echo htmlspecialchars(strtoupper($mailDriver), ENT_QUOTES, 'UTF-8'); ?>.</p>

        <div class="info">
            <strong>Driver:</strong> <?php echo htmlspecialchars(strtoupper($mailDriver), ENT_QUOTES, 'UTF-8'); ?><br>
            <strong>From:</strong> <?php echo htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8'); ?><br>
            <?php if ($mailDriver === 'graph'): ?>
            <strong>Graph config:</strong> <?php echo $graphReady ? 'tenant/client/secret set' : 'NOT configured — see Azure steps below'; ?><br>
            <?php else: ?>
            <strong>SMTP:</strong> <?php echo htmlspecialchars($mailConfig['smtp_host'] . ':' . $mailConfig['smtp_port'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($smtpMode, ENT_QUOTES, 'UTF-8'); ?>)<br>
            <?php endif; ?>
            <strong>PHP curl:</strong> <?php echo $curlReady ? 'enabled' : 'DISABLED'; ?> |
            <strong>OpenSSL:</strong> <?php echo $opensslReady ? 'enabled' : 'DISABLED'; ?><br>
            <strong>Config file:</strong> <?php echo htmlspecialchars($mailConfigPath, ENT_QUOTES, 'UTF-8'); ?><br>
            <strong>php.ini:</strong> <?php echo htmlspecialchars($phpIni, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <?php if ($mailDriver === 'graph' && !$graphReady): ?>
            <div class="err">
                Graph mail is not configured yet. Complete Azure App Registration, then paste
                <code>tenant_id</code>, <code>client_id</code>, and <code>client_secret</code> into
                <code><?php echo htmlspecialchars($mailConfigPath, ENT_QUOTES, 'UTF-8'); ?></code>
                (outside wwwroot).
            </div>
        <?php endif; ?>

        <?php if (!$curlReady || !$opensslReady): ?>
            <div class="err">
                IIS PHP needs <code>extension=curl</code> and <code>extension=openssl</code> enabled in php.ini, then restart IIS (<code>iisreset</code>).
                Graph mail uses HTTPS only — no SMTP ports required.
            </div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="ok"><?php echo htmlspecialchars($result, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="err"><strong>Send failed:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (stripos($error, '5.7.139') !== false || stripos($error, 'security defaults') !== false): ?>
            <div class="info" style="margin-top:12px;">
                <strong>Microsoft 365 admin fix (not an app bug):</strong>
                <ol style="margin:8px 0 0 18px;padding:0;line-height:1.6;">
                    <li>Ask your M365 admin to sign in to <a href="https://admin.microsoft.com" target="_blank" rel="noopener">admin.microsoft.com</a>.</li>
                    <li><strong>Enable SMTP AUTH</strong> for <code>Deliveries@fandoes.com</code> (Exchange admin → Mailboxes → mailbox → Mail → Manage email apps → Authenticated SMTP = On).</li>
                    <li><strong>Allow SMTP in the tenant</strong> (Exchange Online PowerShell):<br>
                        <code>Set-TransportConfig -SmtpClientAuthenticationDisabled $false</code><br>
                        <code>Set-CASMailbox -Identity Deliveries@fandoes.com -SmtpClientAuthenticationDisabled $false</code>
                    </li>
                    <li><strong>Security Defaults</strong> blocks basic SMTP login. Admin must either disable Security Defaults and use Conditional Access with MFA, or create a policy that allows SMTP AUTH for this mailbox.</li>
                    <li>If the account uses MFA, create an <strong>app password</strong> and use that in the external mail config file instead of the normal password.</li>
                </ol>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="send_test" value="1">
            <?php if (erp_is_embedded()): ?>
            <input type="hidden" name="embed" value="1">
            <?php endif; ?>

            <label for="to_email">To email</label>
            <input type="email" id="to_email" name="to_email" value="<?php echo htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8'); ?>" required>

            <label for="doc_no">DO number (optional — uses sample if not found)</label>
            <input type="text" id="doc_no" name="doc_no" value="<?php echo htmlspecialchars($doc_no, ENT_QUOTES, 'UTF-8'); ?>" placeholder="DO-TEST-001">

            <button type="submit" class="btn">Send test email</button>
            <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Back to DO Dispatch</a>
        </form>

        <?php if (!empty($mailLog)): ?>
            <p class="meta"><strong>Mail log</strong></p>
            <pre><?php echo htmlspecialchars(implode("\n", $mailLog), ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
