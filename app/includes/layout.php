<?php
declare(strict_types=1);

function icon(string $name): string
{
    $p = [
        'home'    => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'users'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14.6c2.3.3 3.9 1.9 4.5 4.9"/>',
        'card'    => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/><path d="M6.5 15h4"/>',
        'swap'    => '<path d="M4 8h14l-4-4"/><path d="M20 16H6l4 4"/>',
        'chart'   => '<path d="M3 21h18"/><rect x="5" y="11" width="3" height="7" rx="1"/><rect x="10.5" y="6" width="3" height="12" rx="1"/><rect x="16" y="13" width="3" height="5" rx="1"/>',
        'shield'  => '<path d="M12 2.5 4 5.5v6c0 5 3.4 8.8 8 10 4.6-1.2 8-5 8-10v-6z"/><path d="m9 12 2 2 4-4"/>',
        'log'     => '<path d="M6 2.5h9l4 4V21.5H6z"/><path d="M14.5 2.5v4.5H19"/><path d="M9 12h7M9 15.5h7M9 19h4"/>',
        'logout'  => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
        'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'down'    => '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/>',
        'alert'   => '<path d="M12 3 2 20h20z"/><path d="M12 10v4.5"/><path d="M12 17.5v.5"/>',
        'wallet'  => '<path d="M3.5 7.5h15a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-15z"/><path d="M3.5 7.5 15 3.5v4"/><circle cx="16" cy="14" r="1.2"/>',
        'clock'   => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'lock'    => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function render_header(string $title, string $active): void
{
    $u = current_user();
    // baseline telemetry: every page render that did not already log an action logs a page view
    if (empty($GLOBALS['SB_EVENTS']) && !is_post()) {
        sb_log('page.view', 'success', ['page' => $active ?: 'unknown'], 'INFO');
    }
    $nav = [
        ['dashboard', 'Dashboard', 'dashboard.php', 'home', null],
        ['customers', 'Customers', 'customers.php', 'users', null],
        ['accounts', 'Accounts', 'accounts.php', 'card', null],
        ['transactions', 'Transactions', 'transactions.php', 'swap', null],
        ['reports', 'Reports', 'reports.php', 'chart', 'report.view'],
        ['users', 'User Management', 'users.php', 'shield', 'user.manage'],
        ['audit', 'Audit Logs', 'audit.php', 'log', 'audit.view'],
    ];
    $initials = '';
    foreach (explode(' ', $u['full_name'] ?? 'U') as $w) $initials .= substr($w, 0, 1);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_SHORT) ?></title>
<link rel="icon" type="image/png" href="assets/img/logo-mark.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="app">
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <img src="assets/img/logo-light.png" alt="Senarath Bank">
    <span class="brand-sub">Finance Management System</span>
  </div>
  <nav>
    <?php foreach ($nav as [$key, $label, $href, $ic, $perm]):
        if ($perm && !can($perm)) continue; ?>
      <a class="nav-item<?= $active === $key ? ' active' : '' ?>" href="<?= e($href) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <div class="env-pill"><span class="dot"></span> Internal network · <?= e(gethostname() ?: 'server') ?></div>
    <div class="ver"><?= e(APP_SHORT) ?> v<?= e(APP_VERSION) ?></div>
  </div>
</aside>
<div class="main">
  <header class="topbar">
    <button class="menu-btn" type="button" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu">☰</button>
    <div class="page-title"><h1><?= e($title) ?></h1><span class="crumb"><?= e(date('l, d F Y')) ?></span></div>
    <div class="topbar-right">
      <div class="user-chip">
        <div class="avatar"><?= e(strtoupper(substr($initials, 0, 2))) ?></div>
        <div class="user-meta"><b><?= e($u['full_name'] ?? '') ?></b><span><?= e(ucfirst($u['role'] ?? '')) ?> · <?= e($u['branch'] ?? '') ?></span></div>
      </div>
      <a class="btn btn-ghost" href="logout.php" title="Sign out"><?= icon('logout') ?><span class="hide-sm">Sign out</span></a>
    </div>
  </header>
  <main class="content">
    <?php foreach (take_flash() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach;
}

function render_footer(): void
{
    ?>
  </main>
  <footer class="foot">© <?= date('Y') ?> Senarath Bank PLC · Internal use only · All activity on this system is monitored and logged · Request ID <code><?= e(SB_REQ_ID) ?></code></footer>
</div>
<script src="assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
<?php
}
