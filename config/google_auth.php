<?php
// config/google_auth.php — "Continue with Google" (2026-10-15).
//
// OAuth 2.0 authorization-code flow, server side, no library: auth/google.php sends the visitor to Google,
// auth/google_callback.php takes the one-time code back, swaps it for the user's identity straight from Google
// over TLS with our client secret (so the ID token is trusted per OpenID Connect, and its claims are still
// checked: audience, issuer, expiry, verified email), then either SIGNS IN the account with that email or
// starts a SIGN-UP: register.php with name + email filled in from Google, no password, no email code.
//
// Credentials live in config/google_secret.php (gitignored, like mail_secret.php):
//     <?php define('GOOGLE_CLIENT_ID', '...apps.googleusercontent.com'); define('GOOGLE_CLIENT_SECRET', '...');
// Without that file the Google buttons simply do not show.
//
// Any Google account may sign in or up (most students have no school Google account). Staff accounts are never
// CREATED through Google — they are Admin-made — Google can only sign an existing staff account in.

if (is_file(__DIR__ . '/google_secret.php')) require_once __DIR__ . '/google_secret.php';

function rd_google_enabled(): bool
{
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '' && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== '';
}

/** The exact callback address registered in Google Cloud (Authorized redirect URI). */
function rd_google_redirect_uri(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rd_app_url() . '/auth/google_callback.php';
}

function rd_google_auth_url(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => rd_google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
}

/**
 * Swap the code for the person's identity. Returns ['email','name','sub'] or null (with $err set).
 * Only an email Google has VERIFIED is accepted — that is what lets a Google sign-up skip the email code.
 */
function rd_google_identity(string $code, ?string &$err = null): ?array
{
    $body = http_build_query([
        'code' => $code, 'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => rd_google_redirect_uri(), 'grant_type' => 'authorization_code',
    ]);
    if (function_exists('curl_init')) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {                                                   // servers without php-curl
        $raw = @file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body, 'timeout' => 15, 'ignore_errors' => true]]));
        $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int)$m[1] : 0;
    }
    $tok = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 200 || empty($tok['id_token'])) { $err = 'Google did not confirm the sign-in. Please try again.'; error_log('google token: ' . $status . ' ' . substr((string)$raw, 0, 300)); return null; }

    $parts = explode('.', $tok['id_token']);
    $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    if (!is_array($claims)
        || ($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID
        || !in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
        || (int)($claims['exp'] ?? 0) < time()) {
        $err = 'That Google sign-in could not be checked. Please try again.';
        return null;
    }
    if (empty($claims['email']) || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL) || !in_array($claims['email_verified'] ?? false, [true, 'true'], true)) {
        $err = 'Your Google account has no verified email address.';
        return null;
    }
    return ['email' => strtolower($claims['email']), 'name' => trim((string)($claims['name'] ?? '')), 'sub' => (string)($claims['sub'] ?? '')];
}

/**
 * Sign a user row in: the same session auth/login.php builds on a password sign-in (keep the two in step),
 * a fresh session id, then the URL to send them to (forced password change and the safe login_next first).
 */
function rd_session_login(PDO $pdo, array $user): string
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['department'] = $user['department'] ?? '';
    $_SESSION['program']    = $user['program'] ?? '';
    $group = $user['research_group_name'];
    if (!empty($user['leader_id'])) {
        $l = $pdo->prepare("SELECT research_group_name FROM users WHERE user_id = ?");
        $l->execute([$user['leader_id']]);
        if ($n = $l->fetchColumn()) $group = $n;
    }
    $_SESSION['research_group_name'] = $group ?: 'Research Group';

    $base = rd_app_url() . '/';
    if (!empty($user['must_change_password'])) return $base . 'auth/change_password.php';
    if (!empty($_SESSION['login_next'])) {                       // same guard as login.php: only a path inside this app
        $next = (string)$_SESSION['login_next'];
        unset($_SESSION['login_next']);
        if (strpos($next, rd_app_url() . '/dashboards/') === 0 && preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $next) && strpos($next, '..') === false && strpos($next, '//') === false
            && (strpos($next, '/dashboards/student.php') === false || $user['role'] === 'Student')) {
            return $next;
        }
    }
    $home = rd_home_for((string)$user['role']);          // config/system.php: the dashboard file per role
    return $base . ($home !== '' ? 'dashboards/' . $home : 'auth/login.php');
}

/**
 * The "Continue with Google" button (Google's own look: white, the four-colour G), with an "or" rule above it.
 * Prints nothing until Google is set up, so the pages never show a button that cannot work.
 */
function rd_google_button(string $label = 'Continue with Google', string $href = 'google.php'): void
{
    if (!rd_google_enabled()) return; ?>
    <div style="display:flex;align-items:center;gap:12px;margin:18px 0 14px;color:#9a97b3;font-size:12px;font-weight:600;letter-spacing:.06em">
        <span style="flex:1;height:1px;background:#e6e4f3"></span>OR<span style="flex:1;height:1px;background:#e6e4f3"></span>
    </div>
    <a href="<?= htmlspecialchars($href) ?>" style="display:flex;align-items:center;justify-content:center;gap:12px;width:100%;height:50px;border-radius:999px;border:1px solid #dadce0;background:#fff;color:#1f1f1f;font:600 15px/1 'Plus Jakarta Sans',system-ui,sans-serif;text-decoration:none;box-sizing:border-box">
        <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        <?= htmlspecialchars($label) ?>
    </a>
<?php }
