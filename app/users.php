<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_perm('user.manage');
$roles = ['admin', 'manager', 'teller', 'auditor'];
$branches = ['Head Office', 'Colombo Main', 'Kandy', 'Galle', 'Matara', 'Negombo'];

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $un = strtolower(post('username')); $fn = post('full_name'); $role = post('role'); $br = post('branch'); $pw = (string)($_POST['password'] ?? '');
        $err = null;
        if (!preg_match('/^[a-z][a-z0-9._-]{2,30}$/', $un)) $err = 'Username: 3-31 chars, letters/digits/._-';
        elseif (strlen($fn) < 3) $err = 'Full name is required.';
        elseif (!in_array($role, $roles, true) || !in_array($br, $branches, true)) $err = 'Select a role and branch.';
        elseif (strlen($pw) < 8 || !preg_match('/\d/', $pw) || !preg_match('/[A-Z]/', $pw)) $err = 'Password: min 8 chars with an uppercase letter and a digit.';
        elseif (q('SELECT 1 FROM users WHERE username = ?', [$un])->fetchColumn()) $err = 'Username already exists.';
        if ($err) {
            sb_log('user.create', 'failure', ['target_user' => $un, 'reason' => 'validation'], 'INFO');
            flash('error', $err);
            redirect('users.php?new=1');
        }
        q('INSERT INTO users (username, full_name, password_hash, role, branch) VALUES (?,?,?,?,?)', [$un, $fn, password_hash($pw, PASSWORD_DEFAULT), $role, $br]);
        sb_log('user.create', 'success', ['target_user' => $un, 'target_role' => $role, 'branch' => $br], $role === 'admin' ? 'HIGH' : 'WARN');
        flash('success', "User $un created with role $role.");
        redirect('users.php');
    }

    $tid = (int)post('user_id');
    $t = q('SELECT * FROM users WHERE id = ?', [$tid])->fetch();
    if (!$t) { flash('error', 'User not found.'); redirect('users.php'); }
    $self = (int)$t['id'] === (int)$me['id'];

    switch ($action) {
        case 'role':
            $role = post('role');
            if (!in_array($role, $roles, true) || $self) { flash('error', $self ? 'You cannot change your own role.' : 'Invalid role.'); break; }
            if ($role === $t['role']) break;
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $tid]);
            $esc = array_search($role, ['teller', 'auditor', 'manager', 'admin']) > array_search($t['role'], ['teller', 'auditor', 'manager', 'admin']);
            sb_log('user.role.change', 'success', ['target_user' => $t['username'], 'old_role' => $t['role'], 'new_role' => $role, 'escalation' => $esc], $role === 'admin' ? 'HIGH' : 'WARN');
            flash('success', "{$t['username']} is now $role.");
            break;
        case 'disable':
            if ($self) { flash('error', 'You cannot disable your own account.'); break; }
            q("UPDATE users SET status = 'disabled' WHERE id = ?", [$tid]);
            sb_log('user.disable', 'success', ['target_user' => $t['username']], 'WARN');
            flash('success', "{$t['username']} disabled.");
            break;
        case 'enable':
        case 'unlock':
            q("UPDATE users SET status = 'active', failed_attempts = 0 WHERE id = ?", [$tid]);
            sb_log('user.' . $action, 'success', ['target_user' => $t['username'], 'previous_status' => $t['status']], 'WARN');
            flash('success', "{$t['username']} is active again.");
            break;
        case 'reset':
            $pw = 'Sb@' . random_int(100000, 999999) . 'x';
            q('UPDATE users SET password_hash = ?, failed_attempts = 0 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $tid]);
            sb_log('user.password.reset', 'success', ['target_user' => $t['username']], 'WARN');
            flash('info', "Temporary password for {$t['username']}: $pw");
            break;
        default:
            flash('error', 'Unknown action.');
    }
    redirect('users.php');
}

$list = q('SELECT * FROM users ORDER BY role, username')->fetchAll();
render_header('User Management', 'users');
?>
<div class="toolbar">
  <p class="muted"><?= count($list) ?> system users · accounts lock after <?= MAX_FAILED_LOGINS ?> failed sign-ins</p>
  <button class="btn btn-primary" type="button" data-toggle="new-user"><?= icon('plus') ?> New user</button>
</div>

<div class="card panel<?= get('new') ? ' open' : '' ?>" id="new-user">
  <div class="card-head"><h3>Create user</h3></div>
  <form method="post" class="form form-grid" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>Username<input name="username" required></label>
    <label>Full name<input name="full_name" required></label>
    <label>Role<select name="role"><?php foreach ($roles as $r): ?><option<?= $r === 'teller' ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
    <label>Branch<select name="branch"><?php foreach ($branches as $b): ?><option><?= e($b) ?></option><?php endforeach; ?></select></label>
    <label>Initial password<input name="password" type="password" required autocomplete="new-password"></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create user</button></div>
  </form>
</div>

<div class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>User</th><th>Role</th><th>Branch</th><th>Status</th><th>Last sign-in</th><th class="num">Failed</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($list as $t): $self = (int)$t['id'] === (int)$me['id']; ?>
      <tr>
        <td><b><?= e($t['full_name']) ?></b><br><span class="muted"><?= e($t['username']) ?><?= $self ? ' (you)' : '' ?></span></td>
        <td>
          <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
            <select name="role" onchange="this.form.submit()"<?= $self ? ' disabled' : '' ?>><?php foreach ($roles as $r): ?><option<?= $r === $t['role'] ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
          </form>
        </td>
        <td><?= e($t['branch']) ?></td>
        <td><?= badge($t['status']) ?></td>
        <td><?= $t['last_login'] ? e(date('d M Y H:i', strtotime($t['last_login']))) . '<br><span class="muted">' . e($t['last_ip']) . '</span>' : '<span class="muted">never</span>' ?></td>
        <td class="num"><?= (int)$t['failed_attempts'] ?></td>
        <td>
          <form method="post" class="btn-row">
            <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
            <?php if ($t['status'] === 'locked'): ?><button class="btn btn-sm" name="action" value="unlock">Unlock</button><?php endif; ?>
            <?php if ($t['status'] === 'disabled'): ?><button class="btn btn-sm" name="action" value="enable">Enable</button><?php endif; ?>
            <?php if ($t['status'] !== 'disabled' && !$self): ?><button class="btn btn-sm btn-danger" name="action" value="disable" data-confirm="Disable <?= e($t['username']) ?>?">Disable</button><?php endif; ?>
            <button class="btn btn-sm btn-ghost" name="action" value="reset" data-confirm="Reset password for <?= e($t['username']) ?>?">Reset password</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
