<?php
// config/system.php — the Admin's system tools, shared by every request (2026-10-01).
//
// Loaded by config/db.php. Everything here fails soft: on a database without the 2026-10-01
// migration each helper returns its default and every page behaves exactly as before.
//
//   rd_setting()        system parameters (system_settings, edited in dashboards/admin_settings.php)
//   rd_log_login()      the access log (login_attempts), written by auth/login.php
//   rd_locked_minutes() the lockout: N wrong passwords since the last success/unlock
//   rd_app_url()        the app root, for redirects from any folder

/** Every setting, read once per request. */
function rd_settings(): array
{
    return $GLOBALS['RD_SETTINGS'] ?? [];
}

/** One setting, or $default when it is missing or empty. */
function rd_setting(string $key, $default = null)
{
    $v = rd_settings()[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

/** A whole-number setting clamped to [$min, $max]. */
function rd_setting_int(string $key, int $default, int $min, int $max): int
{
    $v = rd_setting($key);
    if ($v === null || !is_numeric($v)) return $default;
    return max($min, min($max, (int)$v));
}

/** The app root URL path (e.g. /Research_Digital), from whichever folder the script runs in. */
function rd_app_url(): string
{
    return rtrim(preg_replace('#/(dashboards|auth|config)(/.*)?$#', '', dirname($_SERVER['SCRIPT_NAME'] ?? '') . '/'), '/');
}

/** Record one sign-in attempt. Never throws: logging must not stop anyone signing in. */
function rd_log_login(PDO $pdo, string $email, ?int $userId, bool $ok, string $reason): void
{
    try {
        $pdo->prepare("INSERT INTO login_attempts (user_id, email, success, reason, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, mb_substr($email, 0, 100), $ok ? 1 : 0, $reason,
                       mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                       mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    } catch (Throwable $e) {
    }
}

/**
 * Minutes left on this email's lockout, or 0. Wrong passwords are counted inside the window and
 * only after the latest success or Admin unlock, so one good sign-in (or an unlock) clears it.
 * Ages are computed in MySQL — PHP runs Europe/Berlin and would misread stored DATETIMEs.
 */
function rd_locked_minutes(PDO $pdo, string $email): int
{
    try {
        $max  = rd_setting_int('lockout_attempts', 5, 3, 20);
        $mins = rd_setting_int('lockout_minutes', 15, 1, 1440);
        $st = $pdo->prepare("
            SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS since_last
            FROM login_attempts
            WHERE email = ? AND reason = 'wrong_password'
              AND created_at > NOW() - INTERVAL ? MINUTE
              AND created_at > COALESCE((SELECT MAX(created_at) FROM login_attempts
                                         WHERE email = ? AND reason IN ('ok', 'unlock')), '1970-01-01')");
        $st->execute([$email, $mins, $email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$r['n'] < $max) return 0;
        return max(1, (int)ceil(($mins * 60 - (int)$r['since_last']) / 60));
    } catch (Throwable $e) {
        return 0;
    }
}

/** Help Desk categories — key => label. The one list the form, the inbox and the handler share. */
function rd_help_categories(): array
{
    return [
        'email_access' => 'I can\'t get into my email',
        'password'     => 'Reset my password',
        'fix_details'  => 'Fix my name, email or School ID',
        'group_change' => 'Move me to another group or section',
        'suggestion'   => 'A suggestion for the system',
        'other'        => 'Something else',
    ];
}

/** The dashboard a role lands on after signing in (mirrors auth/login.php's switch). */
function rd_home_for(string $role): string
{
    return [
        'Student' => 'student.php', 'Research Coordinator' => 'coordinator.php', 'Statistician' => 'statistician.php',
        'Research Director' => 'director.php', 'Focal Person' => 'focal.php', 'Admin' => 'admin.php',
    ][$role] ?? '';
}

/** Emails locked out right now => minutes left. The Access log and the Admin's badge both ask this. */
function rd_locked_emails(PDO $pdo): array
{
    $out = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT email FROM login_attempts WHERE reason = 'wrong_password' AND created_at > NOW() - INTERVAL ? MINUTE");
        $st->execute([rd_setting_int('lockout_minutes', 15, 1, 1440)]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $em) {
            if ($m = rd_locked_minutes($pdo, $em)) $out[$em] = $m;
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** The Admin's badge counts, keyed like nav_badge_counts.php. */
function rd_admin_counts(PDO $pdo): array
{
    $n = ['acct_pending' => 0, 'help_open' => 0, 'locked_now' => count(rd_locked_emails($pdo))];
    try {
        $n['acct_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'Student' AND account_status = 'pending'")->fetchColumn();
        $n['help_open']    = (int)$pdo->query("SELECT COUNT(*) FROM help_requests WHERE status = 'open'")->fetchColumn();
    } catch (Throwable $e) {
    }
    return $n;
}

/**
 * The address QR codes point at (2026-10-01). The Admin sets it on the QR codes screen
 * (system_settings.site_url) — it must be what a PHONE can reach, e.g. http://192.168.1.20/Research_Digital
 * or the real domain, never "localhost". Unset, it is the address this request came in on.
 */
function rd_site_url(): string
{
    $set = rtrim(trim((string)rd_setting('site_url', '')), '/');
    if ($set !== '') return $set;
    // Deployed: the address people actually used, including behind a proxy / load balancer
    // that terminates HTTPS and forwards plain HTTP.
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $proto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host  = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    $host  = trim(explode(',', $host)[0]);
    // Testing on the server itself: "localhost" means the PHONE to a phone, so swap in this
    // computer's own network address — the one a phone on the same Wi-Fi can open.
    if ($host === '' || preg_match('#^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#i', $host)) {
        $port = preg_match('#:(\d+)$#', $host, $m) ? ':' . $m[1] : '';
        $host = (rd_lan_ip() ?? 'localhost') . $port;
    }
    return ($https ? 'https' : 'http') . '://' . $host . rd_app_url();
}

/** This computer's address on the local network (Wi-Fi / hotspot), or null. */
function rd_lan_ip(): ?string
{
    $ips = @gethostbynamel((string)gethostname()) ?: [];
    foreach ($ips as $ip) {
        if (!preg_match('#^(127\.|169\.254\.|0\.)#', $ip)) return $ip;
    }
    return null;
}

/** The short link a printed Admin QR carries: {site}/q.php?c=CODE. */
function rd_qr_link_url(string $code): string
{
    return rd_site_url() . '/q.php?c=' . rawurlencode($code);
}

// The blank forms students download, per institution (2026-10-05) — Admin → System → Forms.
require_once __DIR__ . '/forms.php';
