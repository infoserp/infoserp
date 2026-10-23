<?php
/**
 * DO Dispatch — JSON API for prepare/send email (includes copy).
 *
 * POST JSON:
 *   { "action": "prepare", "doc_no": "..." }
 *   { "action": "add_email", "doc_no": "...", "email": "..." }
 *   { "action": "send", "doc_no": "...", "confirm": true, "emails": ["a@b.com", ...] }
 */

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

if (!defined('DO_SEND_JSON_API')) {
    define('DO_SEND_JSON_API', true);
}

ob_start();

function doSendJson($payload, $httpCode = 200)
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (headers_sent()) {
        error_log(sprintf(
            'do_send fatal (headers already sent): %s in %s:%d',
            $err['message'],
            $err['file'],
            $err['line']
        ));
        return;
    }
    error_log(sprintf(
        'do_send fatal: %s in %s:%d',
        $err['message'],
        $err['file'],
        $err['line']
    ));
    doSendJson([
        'success' => false,
        'message' => 'Server error: ' . $err['message'],
    ], 500);
});

try {
    require __DIR__ . '/../db.php';

    $user = trim((string) ($_SESSION['username'] ?? ''));
    if ($user === '') {
        doSendJson(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        doSendJson(['success' => false, 'message' => 'Invalid request method.'], 405);
    }

    require_once __DIR__ . '/do_print_service.php';
    require_once __DIR__ . '/do_mail_config_loader.php';

    $company_code = $_SESSION['company_code'] ?? '';
    $branch_code  = $_SESSION['branch_code'] ?? '';

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        doSendJson(['success' => false, 'message' => 'Invalid request body.']);
    }

    $action = strtolower(trim((string) ($data['action'] ?? '')));
    $doc_no = trim((string) ($data['doc_no'] ?? ''));

    if ($doc_no === '') {
        doSendJson(['success' => false, 'message' => 'Missing delivery order number.']);
    }

    if (!in_array($action, ['prepare', 'add_email', 'send'], true)) {
        doSendJson(['success' => false, 'message' => 'Invalid action.']);
    }

    $orderData = doPrintValidateDispatchAccess($con, $company_code, $branch_code, $user, $doc_no);
    $ac_code   = trim((string) ($orderData['do_header']['ac_code'] ?? ''));
    $customer  = trim((string) ($orderData['do_header']['customer_name'] ?? $ac_code));
    $contacts  = doPrintFetchCustomerEmails($con, $company_code, $ac_code);
    $emails    = array_column($contacts, 'email');
    $emailList = implode(', ', $emails);

    if ($action === 'prepare') {
        doSendJson([
            'success'         => true,
            'step'            => 'confirm',
            'doc_no'          => $doc_no,
            'ac_code'         => $ac_code,
            'customer_name'   => $customer,
            'customer_email'  => $emailList,
            'customer_emails' => $emails,
            'contacts'        => $contacts,
            'message'         => empty($emails)
                ? 'No customer email found. Add an email to continue.'
                : 'Confirm sending Delivery Order ' . $doc_no . ' to ' . $emailList . '.',
        ]);
    }

    if ($action === 'add_email') {
        $newEmail = trim((string) ($data['email'] ?? ''));
        $added    = doPrintAddCustomerEmail($con, $company_code, $ac_code, $newEmail);
        $contacts = doPrintFetchCustomerEmails($con, $company_code, $ac_code);
        $emails   = array_column($contacts, 'email');
        $emailList = implode(', ', $emails);

        doSendJson([
            'success'         => true,
            'doc_no'          => $doc_no,
            'ac_code'         => $ac_code,
            'customer_name'   => $customer,
            'added'           => $added,
            'customer_email'  => $emailList,
            'customer_emails' => $emails,
            'contacts'        => $contacts,
            'message'         => 'Email saved for customer.',
        ]);
    }

    if (empty($data['confirm'])) {
        doSendJson(['success' => false, 'message' => 'Email send was not confirmed.']);
    }

    // Prefer client-selected recipients; default to all known emails.
    $selectedRaw = $data['emails'] ?? null;
    if (is_array($selectedRaw)) {
        $allowed = [];
        foreach ($emails as $em) {
            $allowed[strtolower($em)] = $em;
        }
        $emails = [];
        $seen   = [];
        foreach ($selectedRaw as $em) {
            $em = trim((string) $em);
            if ($em === '') {
                continue;
            }
            $key = strtolower($em);
            if (!isset($allowed[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $emails[]   = $allowed[$key];
        }
        $emailList = implode(', ', $emails);
    }

    if (empty($emails)) {
        doSendJson([
            'success' => false,
            'message' => 'Select at least one customer email, or add an email first.',
        ]);
    }

    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    $pdfBinary = doPrintGeneratePdfBinary($orderData);
    if ($pdfBinary === '' || $pdfBinary === false) {
        throw new RuntimeException('PDF generation failed.');
    }

    $storagePaths = doPrintResolveStoragePaths($con, $company_code, $branch_code);
    $saved        = doPrintSavePdf($storagePaths['dir'], $doc_no, $pdfBinary);

    $attachment = doPrintInsertDocAttachment(
        $con,
        $company_code,
        $branch_code,
        $doc_no,
        'DLO',
        $storagePaths['file_path'],
        $saved['file_name'],
        $user
    );

    $mailConfig = erp_load_do_mail_config();

    $companyName = doPrintFetchCompanyName($con, $company_code);
    if ($companyName === '') {
        $companyName = $customer;
    }
    $subject = 'Delivery Order ' . $doc_no . ' - ' . $companyName;

    doMailSendDeliveryOrder($mailConfig, $emails, $subject, $orderData, [
        'name' => $saved['file_name'],
        'mime' => 'application/pdf',
        'data' => $pdfBinary,
    ]);

    $despatch_date = doPrintMarkDispatched($con, $company_code, $branch_code, $user, $doc_no);

    doSendJson([
        'success'         => true,
        'message'         => 'Delivery Order ' . $doc_no . ' emailed to ' . $emailList . '.',
        'doc_no'          => $doc_no,
        'email'           => $emailList,
        'customer_emails' => $emails,
        'file_name'       => $saved['file_name'],
        'pdf_path'        => $saved['full_path'],
        'attachment'      => $attachment,
        'despatch_date'   => $despatch_date,
    ]);

} catch (Throwable $e) {
    error_log('do_send error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    doSendJson([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
