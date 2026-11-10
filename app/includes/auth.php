<?php
declare(strict_types=1);

const SB_PERMISSIONS = [
    'customer.create' => ['admin', 'manager', 'teller'],
    'account.open'    => ['admin', 'manager', 'teller'],
    'account.freeze'  => ['admin', 'manager'],
    'txn.post'        => ['admin', 'manager', 'teller'],
    'txn.high_value'  => ['admin', 'manager'],
    'report.view'     => ['admin', 'manager', 'auditor'],
    'report.export'   => ['admin', 'manager', 'auditor'],
    'audit.view'      => ['admin', 'auditor'],
    'user.manage'     => ['admin'],
];

function sb_session_start(): void
{
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $path = DATA_DIR . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($path)) @mkdir($path, 0775, true);
    if (is_dir($path) && is_writable($path)) session_save_path($path);
    session_name('SBFMSSESSID');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $secure]);
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function can(string $perm): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], SB_PERMISSIONS[$perm] ?? [], true);
}

function sb_end_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    $idle = time() - (int)($_SESSION['last_activity'] ?? 0);
    if ($idle > SESSION_IDLE_SECONDS) {
        sb_log('auth.session.expired', 'failure', ['idle_seconds' => $idle], 'INFO');
        sb_end_session();
        redirect('login.php?expired=1');
    }
    $row = q('SELECT status, role FROM users WHERE id = ?', [$u['id']])->fetch();
    if (!$row || $row['status'] !== 'active') {
        sb_log('auth.session.revoked', 'denied', ['reason' => $row ? 'account_' . $row['status'] : 'account_missing'], 'WARN');
        sb_end_session();
        redirect('login.php?revoked=1');
    }
    if ($row['role'] !== $u['role']) {           // role changed by an admin while logged in
        $_SESSION['user']['role'] = $row['role'];
        $u['role'] = $row['role'];
    }
    $_SESSION['last_activity'] = time();
    return $u;
}

/** Require a permission; logs access.denied and returns HTTP 403 otherwise. */
function require_perm(string $perm): array
{
    $u = require_login();
    if (!can($perm)) {
        sb_log('access.denied', 'denied', ['permission' => $perm, 'resource' => $_SERVER['SCRIPT_NAME'] ?? '-'], 'WARN');
        http_response_code(403);
        render_header('Access denied', '');
        echo '<div class="card empty-state"><div class="empty-icon">' . icon('shield') . '</div><h2>Access denied</h2>'
           . '<p>Your role <b>' . e($u['role']) . '</b> is not permitted to use this function. This attempt has been logged.</p>'
           . '<a class="btn" href="dashboard.php">Back to dashboard</a></div>';
        render_footer();
        exit;
    }
    return $u;
}
