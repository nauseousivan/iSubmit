<?php
// config/db.php
$host = 'localhost';
$dbname = 'digital_research'; // Fixed to match your exact database name!
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// CSRF token (per session). Used to protect state-changing proposal actions.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// SYSTEM SETTINGS (2026-10-01): the Admin's parameters, read once per request. See config/system.php.
require_once __DIR__ . '/system.php';
$GLOBALS['RD_SETTINGS'] = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings") as $rd_row) {
        $GLOBALS['RD_SETTINGS'][$rd_row['setting_key']] = $rd_row['setting_value'];
    }
} catch (Throwable $e) {
    // Table missing (migration not run): every rd_setting() falls back to its default.
}

/** Leave this request: redirect a page load, answer a fetch() with JSON. */
if (!function_exists('rd_bounce')) { function rd_bounce(string $url, int $code, string $msg): void
{
    if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate') === 'navigate') {
        header('Location: ' . $url);
    } else {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'ok' => false, 'status' => 'error', 'error' => $msg, 'message' => $msg]);
    }
    exit;
} }

// ACCOUNT STATUS (2026-09-30). An account the Admin disables, rejects or has not approved yet is
// signed out on its NEXT request, not whenever its session happens to expire. users.account_status
// is the switch — never is_verified, which the user can flip back themself through the email OTP.
// 2026-10-01: the same check signs out everyone but the Admin while MAINTENANCE MODE is on, and
// holds anyone the Admin reset on auth/change_password.php until they choose their own password.
// The try/catch keeps every page working on a database that has not run the migrations yet.
if (!empty($_SESSION['user_id']) && PHP_SAPI !== 'cli') {
    $rd_row = null;
    try {
        $rd_st = $pdo->prepare("SELECT account_status, role FROM users WHERE user_id = ?");
        $rd_st->execute([(int)$_SESSION['user_id']]);
        $rd_row = $rd_st->fetch(PDO::FETCH_ASSOC) ?: false;
    } catch (Throwable $e) {
        // Column missing (migration not run): behave exactly as before.
    }
    if ($rd_row === false || ($rd_row && $rd_row['account_status'] !== 'active')) {
        $_SESSION = [];
        session_destroy();
        rd_bounce(rd_app_url() . '/auth/login.php?blocked=1', 401, 'Your account is no longer active.');
    }
    if ($rd_row && rd_setting('maintenance_mode') === '1' && $rd_row['role'] !== 'Admin') {
        $_SESSION = [];
        session_destroy();
        rd_bounce(rd_app_url() . '/auth/login.php?maintenance=1', 503, 'The system is under maintenance. Please try again later.');
    }
    if ($rd_row) {
        try {
            $rd_mc = $pdo->prepare("SELECT must_change_password FROM users WHERE user_id = ?");
            $rd_mc->execute([(int)$_SESSION['user_id']]);
            if ((int)$rd_mc->fetchColumn() === 1
                && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['change_password.php', 'logout.php', 'help.php'], true)) {
                rd_bounce(rd_app_url() . '/auth/change_password.php', 403, 'Choose a new password first.');
            }
        } catch (Throwable $e) {
        }
    }
}

// THE ADMIN IS VIEW-ONLY IN THE RESEARCH WORKFLOW (2026-09-30). The pages that let the Admin in
// render with every button the office has, so the rule lives here, once, rather than in each of
// the dozens of POST branches: an Admin may POST only to the account-maintenance handlers, the
// system tools (2026-10-01) and her own settings. Hiding buttons in the UI is courtesy; this is the rule.
if (($_SESSION['role'] ?? '') === 'Admin' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['admin_accounts_handler.php', 'admin_students_handler.php',
        'admin_help_handler.php', 'admin_system_handler.php', 'admin_qr_handler.php', 'admin_forms_handler.php', 'staff_settings_handler.php', 'change_password.php',
        'help.php', 'logout.php'], true)) {
    $rd_msg = 'The Admin account can view the research workflow but not act in it.';
    if (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'navigate') === 'navigate') {
        $_SESSION['message'] = $rd_msg;
        $_SESSION['message_type'] = 'error';
        $_SESSION['flash_message'] = $rd_msg;
    }
    rd_bounce($_SERVER['HTTP_REFERER'] ?? (rd_app_url() . '/dashboards/admin.php'), 403, $rd_msg);
}
