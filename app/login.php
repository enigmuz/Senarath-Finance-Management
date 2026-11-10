<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('dashboard.php');

$error = null;
$username = '';

if (is_post()) {
    csrf_check();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');
    $u = $username !== '' ? q('SELECT * FROM users WHERE username = ?', [$username])->fetch() : false;

    if (!$u) {
        sb_log('auth.login.failure', 'failure', ['user' => $username, 'reason' => 'unknown_user'], 'WARN');
        $error = 'Invalid username or password.';
        http_response_code(401);
    } elseif ($u['status'] !== 'active') {
        sb_log('auth.login.blocked', 'denied', ['user' => $u['username'], 'reason' => 'account_' . $u['status']], 'WARN');
        $error = $u['status'] === 'locked'
            ? 'This account is locked after too many failed attempts. Contact the system administrator.'
            : 'This account is disabled. Contact the system administrator.';
        http_response_code(403);
    } elseif (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_attempts'] + 1;
        q('UPDATE users SET failed_attempts = ? WHERE id = ?', [$fails, $u['id']]);
        sb_log('auth.login.failure', 'failure', ['user' => $u['username'], 'reason' => 'bad_password', 'failed_attempts' => $fails], 'WARN');
        if ($fails >= MAX_FAILED_LOGINS) {
            q("UPDATE users SET status = 'locked' WHERE id = ?", [$u['id']]);
            sb_log('auth.account.locked', 'success', ['user' => $u['username'], 'failed_attempts' => $fails, 'threshold' => MAX_FAILED_LOGINS], 'HIGH');
            $error = 'Too many failed attempts. The account has been locked.';
        } else {
            $error = 'Invalid username or password.';
        }
        http_response_code(401);
    } else {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'],
            'role' => $u['role'], 'branch' => $u['branch'],
        ];
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        q("UPDATE users SET failed_attempts = 0, last_login = datetime('now','localtime'), last_ip = ? WHERE id = ?", [sb_client_ip(), $u['id']]);
        sb_log('auth.login.success', 'success', ['branch' => $u['branch'], 'previous_login' => $u['last_login'] ?? '-'], 'INFO');
        redirect('dashboard.php');
    }
} else {
    sb_log('page.view', 'success', ['page' => 'login'], 'INFO');
}

$notice = null;
if (isset($_GET['expired'])) $notice = 'Your session expired due to inactivity. Please sign in again.';
if (isset($_GET['logged_out'])) $notice = 'You have been signed out securely.';
if (isset($_GET['revoked'])) $notice = 'Your session was ended because your account status changed.';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e(APP_SHORT) ?></title>
<link rel="icon" type="image/png" href="assets/img/logo-mark.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body class="login-page">
  <section class="login-hero<?= is_file(__DIR__ . '/assets/img/login-bg.jpg') ? ' has-photo' : '' ?>">
    <div class="hero-overlay"></div>
    <div class="hero-content">
      <img class="hero-mark" src="assets/img/logo-mark.png" alt="">
      <h1>Finance Management System</h1>
      <p>Customer, account and transaction operations for Senarath Bank branch and head-office staff.</p>
      <ul class="hero-points">
        <li><?= icon('users') ?> Customer onboarding &amp; CIF management</li>
        <li><?= icon('swap') ?> Deposits, withdrawals &amp; fund transfers</li>
        <li><?= icon('chart') ?> Branch reporting &amp; exports</li>
        <li><?= icon('shield') ?> Role-based access with full audit trail</li>
      </ul>
    </div>
    <div class="hero-foot">A Sri Lankan Savings Bank · Internal system</div>
  </section>

  <section class="login-panel">
    <div class="login-card">
      <img class="login-logo" src="assets/img/logo.png" alt="Senarath Bank">
      <h2>Staff sign in</h2>
      <p class="muted">Use your Senarath Bank FMS credentials.</p>

      <?php if ($notice): ?><div class="flash flash-info"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

      <form method="post" action="login.php" autocomplete="off" class="form">
        <?= csrf_field() ?>
        <label>Username
          <span class="input-ic"><?= icon('user') ?><input name="username" value="<?= e($username) ?>" required autofocus></span>
        </label>
        <label>Password
          <span class="input-ic"><?= icon('lock') ?><input type="password" name="password" required></span>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
      </form>
      <div class="login-warn"><?= icon('alert') ?><span>Authorised personnel only. All access and activity is monitored and recorded.</span></div>
    </div>
    <div class="login-foot"><?= e(APP_SHORT) ?> v<?= e(APP_VERSION) ?> · <?= e(gethostname() ?: '') ?></div>
  </section>
</body>
</html>
