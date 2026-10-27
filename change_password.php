<?php
/**
 * Admin — Change / reset login_password for a branch_user.
 * Menu: user_menu.menu_code = m_changepassword (parent Admin).
 */
ob_start();
require __DIR__ . '/db.php';
include __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/erp_paths.php';
require_once __DIR__ . '/includes/erp_auth.php';
erp_guard_standalone_get();

$user         = $_SESSION['username'] ?? '';
$company_code = $_SESSION['company_code'] ?? '';
$branch_code  = $_SESSION['branch_code'] ?? '';

if (($_SESSION['CPW'] ?? 'N') !== 'Y') {
    ob_end_clean();
    http_response_code(403);
    echo 'Access denied. Enable menu m_changepassword (Change Password) for this user.';
    exit;
}

$message = '';
$messageType = '';

// Users available to reset (distinct user_id from branch_user)
$users = [];
try {
    $stmt = $con->query(
        "SELECT user_id,
                MAX(NULLIF(RTRIM(ISNULL(user_name, '')), '')) AS user_name,
                MAX(NULLIF(RTRIM(ISNULL(user_type, '')), '')) AS user_type
         FROM branch_user
         GROUP BY user_id
         ORDER BY user_id"
    );
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('change_password user list: ' . $e->getMessage());
    $message = 'Could not load users.';
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $targetUser = strtoupper(trim((string) ($_POST['user_id'] ?? '')));
    $newPass    = (string) ($_POST['login_password'] ?? '');
    $confirm    = (string) ($_POST['confirm_password'] ?? '');

    if ($targetUser === '') {
        echo json_encode(['success' => false, 'message' => 'Select a user.']);
        exit;
    }
    if ($newPass === '' || $confirm === '') {
        echo json_encode(['success' => false, 'message' => 'Enter and confirm the new login password.']);
        exit;
    }
    if (!hash_equals($newPass, $confirm)) {
        echo json_encode(['success' => false, 'message' => 'Password and confirmation do not match.']);
        exit;
    }
    if (strlen($newPass) < 4) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 4 characters.']);
        exit;
    }

    $exists = false;
    foreach ($users as $u) {
        if (strtoupper(trim((string) $u['user_id'])) === $targetUser) {
            $exists = true;
            break;
        }
    }
    // Re-check from DB in case list was empty / stale
    if (!$exists) {
        $chk = $con->prepare('SELECT TOP 1 1 FROM branch_user WHERE user_id = ?');
        $chk->execute([$targetUser]);
        $exists = (bool) $chk->fetchColumn();
    }
    if (!$exists) {
        echo json_encode(['success' => false, 'message' => 'User not found in branch_user.']);
        exit;
    }

    if (!erp_auth_upgrade_login_password($con, $targetUser, $newPass)) {
        echo json_encode([
            'success' => false,
            'message' => 'Could not save hashed login_password. Check column width (varchar 255) and user rows.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login password reset for ' . $targetUser . '.',
    ]);
    exit;
}

$page_title            = 'Change Password';
$active_page           = 'CPW';
$defer_approval_counts = true;
include __DIR__ . '/includes/approval_layout_start.php';
?>
        <div class="al-table-wrap" style="max-width:520px;margin:0 auto;">
            <div style="background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);color:#fff;padding:0.875rem 1.25rem;">
                <h5 style="margin:0;font-size:0.9375rem;font-weight:700;">
                    <i class="fa fa-key" aria-hidden="true"></i>
                    Reset Login Password
                </h5>
            </div>
            <form id="cpw-form" class="p-4 sm:p-6" autocomplete="off" style="padding:1.25rem;">
                <input type="hidden" name="action" value="reset">

                <div style="margin-bottom:1rem;">
                    <label for="user_id" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">
                        User
                    </label>
                    <select name="user_id" id="user_id" required
                            style="width:100%;padding:0.55rem 0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.875rem;background:#fff;">
                        <option value="">Select user</option>
                        <?php foreach ($users as $u):
                            $uid = strtoupper(trim((string) ($u['user_id'] ?? '')));
                            $uname = trim((string) ($u['user_name'] ?? ''));
                            $utype = trim((string) ($u['user_type'] ?? ''));
                            $label = $uid;
                            if ($uname !== '' && strtoupper($uname) !== $uid) {
                                $label .= ' — ' . $uname;
                            }
                            if ($utype !== '') {
                                $label .= ' (' . $utype . ')';
                            }
                        ?>
                        <option value="<?php echo htmlspecialchars($uid, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom:1rem;">
                    <label for="login_password" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">
                        New Login Password
                    </label>
                    <input type="password" name="login_password" id="login_password" required minlength="4"
                           autocomplete="new-password"
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.875rem;">
                </div>

                <div style="margin-bottom:1.25rem;">
                    <label for="confirm_password" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.35rem;">
                        Confirm Password
                    </label>
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="4"
                           autocomplete="new-password"
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.875rem;">
                </div>

                <div id="cpw-msg" role="status" aria-live="polite"
                     style="display:none;margin-bottom:1rem;padding:0.65rem 0.85rem;border-radius:0.5rem;font-size:0.8125rem;font-weight:600;"></div>

                <div class="al-form-actions" style="justify-content:flex-start;padding-top:0;">
                    <button type="submit" id="cpw-submit" class="al-home-btn al-home-btn--primary">
                        <i class="fa fa-save" aria-hidden="true"></i>
                        Reset Password
                    </button>
                </div>
            </form>
        </div>

<script>
(function () {
    var form = document.getElementById('cpw-form');
    var msg = document.getElementById('cpw-msg');
    var btn = document.getElementById('cpw-submit');
    if (!form) return;

    function showMsg(text, ok) {
        msg.style.display = 'block';
        msg.textContent = text;
        msg.style.background = ok ? '#ecfdf5' : '#fef2f2';
        msg.style.color = ok ? '#047857' : '#b91c1c';
        msg.style.border = '1px solid ' + (ok ? '#a7f3d0' : '#fecaca');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        btn.disabled = true;
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            showMsg(data.message || (data.success ? 'Done.' : 'Failed.'), !!data.success);
            if (data.success) {
                form.login_password.value = '';
                form.confirm_password.value = '';
            }
        })
        .catch(function () {
            showMsg('Network error — try again.', false);
        })
        .finally(function () {
            btn.disabled = false;
        });
    });
})();
</script>
<?php include __DIR__ . '/includes/approval_layout_end.php'; ?>
