<?php
declare(strict_types=1);

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    sb_emit_headers();
    header('Location: ' . $url, true, 302);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $k, string $default = ''): string
{
    return trim((string)($_POST[$k] ?? $default));
}

function get(string $k, string $default = ''): string
{
    return trim((string)($_GET[$k] ?? $default));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        sb_log('security.csrf.failure', 'denied', ['resource' => $_SERVER['SCRIPT_NAME'] ?? '-'], 'HIGH');
        http_response_code(400);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** cents -> "LKR 1,234.56" */
function money(int $cents, bool $prefix = true): string
{
    $s = number_format($cents / 100, 2);
    return $prefix ? 'LKR ' . $s : $s;
}

/** Compact money for KPI tiles: LKR 12.4M */
function money_short(int $cents): string
{
    $v = $cents / 100;
    if ($v >= 1e9) return 'LKR ' . number_format($v / 1e9, 2) . 'B';
    if ($v >= 1e6) return 'LKR ' . number_format($v / 1e6, 1) . 'M';
    if ($v >= 1e3) return 'LKR ' . number_format($v / 1e3, 1) . 'K';
    return 'LKR ' . number_format($v, 0);
}

/** "12,500.50" -> 1250050 cents, or null if invalid */
function parse_amount(string $s): ?int
{
    $s = str_replace([',', ' '], '', $s);
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $s)) return null;
    $c = (int)round((float)$s * 100);
    return $c > 0 ? $c : null;
}

function badge(string $text, string $kind = ''): string
{
    $map = [
        'active' => 'ok', 'success' => 'ok', 'DEPOSIT' => 'ok', 'TRANSFER_IN' => 'ok',
        'frozen' => 'warn', 'locked' => 'warn', 'WARN' => 'warn', 'WITHDRAWAL' => 'muted', 'TRANSFER_OUT' => 'muted',
        'disabled' => 'bad', 'closed' => 'bad', 'failure' => 'bad', 'denied' => 'bad', 'HIGH' => 'bad', 'CRITICAL' => 'bad',
        'admin' => 'gold', 'manager' => 'navy', 'teller' => 'muted', 'auditor' => 'navy', 'INFO' => 'muted',
    ];
    $k = $kind ?: ($map[$text] ?? 'muted');
    return '<span class="badge badge-' . e($k) . '">' . e(str_replace('_', ' ', $text)) . '</span>';
}
