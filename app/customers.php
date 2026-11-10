<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$branches = ['Colombo Main', 'Kandy', 'Galle', 'Matara', 'Negombo'];

// ---- create customer -------------------------------------------------------
if (is_post() && post('action') === 'create') {
    require_perm('customer.create');
    csrf_check();
    $name = post('full_name'); $nic = strtoupper(post('nic')); $phone = post('phone'); $email = post('email'); $branch = post('branch');
    $errors = [];
    if (strlen($name) < 3) $errors[] = 'Full name is required.';
    if (!preg_match('/^(\d{9}[VX]|\d{12})$/', $nic)) $errors[] = 'NIC must be 9 digits + V/X or 12 digits.';
    if ($phone !== '' && !preg_match('/^0\d{9}$/', $phone)) $errors[] = 'Phone must be 10 digits starting with 0.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
    if (!in_array($branch, $branches, true)) $errors[] = 'Select a branch.';
    if (!$errors && q('SELECT 1 FROM customers WHERE nic = ?', [$nic])->fetchColumn()) $errors[] = 'A customer with this NIC already exists.';

    if ($errors) {
        sb_log('customer.create', 'failure', ['reason' => 'validation', 'errors' => count($errors)], 'INFO');
        foreach ($errors as $er) flash('error', $er);
        redirect('customers.php?new=1');
    }
    $next = 100001 + (int)q('SELECT COUNT(*) FROM customers')->fetchColumn();
    do { $cif = 'CIF' . $next++; } while (q('SELECT 1 FROM customers WHERE cif = ?', [$cif])->fetchColumn());
    q('INSERT INTO customers (cif, full_name, nic, phone, email, branch, created_by) VALUES (?,?,?,?,?,?,?)', [$cif, $name, $nic, $phone, $email, $branch, $u['username']]);
    $id = (int)db()->lastInsertId();
    sb_log('customer.create', 'success', ['cif' => $cif, 'customer_id' => $id, 'branch' => $branch], 'INFO');
    flash('success', "Customer $cif created for $name.");
    redirect('customers.php?id=' . $id);
}

// ---- customer detail -------------------------------------------------------
$id = (int)get('id', '0');
if ($id > 0) {
    $c = q('SELECT * FROM customers WHERE id = ?', [$id])->fetch();
    if (!$c) {
        sb_log('customer.view', 'failure', ['customer_id' => $id, 'reason' => 'not_found'], 'INFO');
        http_response_code(404);
        render_header('Customer not found', 'customers');
        echo '<div class="card empty-state"><h2>Customer not found</h2><a class="btn" href="customers.php">Back</a></div>';
        render_footer(); exit;
    }
    sb_log('customer.view', 'success', ['cif' => $c['cif'], 'customer_id' => $id, 'pii' => true], 'INFO');
    $accts = q('SELECT * FROM accounts WHERE customer_id = ? ORDER BY id', [$id])->fetchAll();
    render_header($c['full_name'], 'customers');
    ?>
    <div class="profile card">
      <div class="avatar avatar-lg"><?= e(strtoupper(substr($c['full_name'], 0, 1))) ?></div>
      <div class="profile-main">
        <h2><?= e($c['full_name']) ?> <?= badge($c['cif'], 'navy') ?></h2>
        <div class="kv-grid">
          <div><span>NIC</span><b><?= e($c['nic']) ?></b></div>
          <div><span>Phone</span><b><?= e($c['phone'] ?: '—') ?></b></div>
          <div><span>Email</span><b><?= e($c['email'] ?: '—') ?></b></div>
          <div><span>Home branch</span><b><?= e($c['branch']) ?></b></div>
          <div><span>Customer since</span><b><?= e(date('d M Y', strtotime($c['created_at']))) ?></b></div>
          <div><span>Onboarded by</span><b><?= e($c['created_by']) ?></b></div>
        </div>
      </div>
      <div class="profile-actions">
        <?php if (can('account.open')): ?><a class="btn btn-primary" href="accounts.php?new=1&customer_id=<?= $id ?>"><?= icon('plus') ?> Open account</a><?php endif; ?>
        <a class="btn" href="customers.php">Back to list</a>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Accounts</h3></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Account no.</th><th>Type</th><th>Status</th><th>Branch</th><th>Opened</th><th class="num">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($accts as $a): ?>
          <tr><td><a href="accounts.php?id=<?= (int)$a['id'] ?>"><?= e($a['account_no']) ?></a></td><td><?= e($a['type']) ?></td><td><?= badge($a['status']) ?></td>
          <td><?= e($a['branch']) ?></td><td><?= e(date('d M Y', strtotime($a['opened_at']))) ?></td><td class="num"><?= e(money((int)$a['balance'])) ?></td></tr>
        <?php endforeach; if (!$accts): ?><tr><td colspan="6" class="muted center">No accounts yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php
    render_footer(); exit;
}

// ---- list / search -----------------------------------------------------------
$search = get('q');
if ($search !== '') {
    $like = '%' . $search . '%';
    $list = q("SELECT c.*, (SELECT COUNT(*) FROM accounts a WHERE a.customer_id = c.id) n, (SELECT COALESCE(SUM(balance),0) FROM accounts a WHERE a.customer_id = c.id) bal
               FROM customers c WHERE c.full_name LIKE ? OR c.nic LIKE ? OR c.cif LIKE ? OR c.phone LIKE ? ORDER BY c.full_name", [$like, $like, $like, $like])->fetchAll();
    sb_log('customer.search', 'success', ['query' => substr($search, 0, 100), 'results' => count($list)], 'INFO');
} else {
    $list = q("SELECT c.*, (SELECT COUNT(*) FROM accounts a WHERE a.customer_id = c.id) n, (SELECT COALESCE(SUM(balance),0) FROM accounts a WHERE a.customer_id = c.id) bal
               FROM customers c ORDER BY c.id DESC")->fetchAll();
}

render_header('Customers', 'customers');
?>
<div class="toolbar">
  <form class="search" method="get" action="customers.php">
    <?= icon('search') ?><input name="q" value="<?= e($search) ?>" placeholder="Search by name, NIC, CIF or phone">
    <button class="btn" type="submit">Search</button>
    <?php if ($search !== ''): ?><a class="btn btn-ghost" href="customers.php">Clear</a><?php endif; ?>
  </form>
  <?php if (can('customer.create')): ?>
    <button class="btn btn-primary" type="button" data-toggle="new-customer"><?= icon('plus') ?> New customer</button>
  <?php endif; ?>
</div>

<?php if (can('customer.create')): ?>
<div class="card panel<?= get('new') ? ' open' : '' ?>" id="new-customer">
  <div class="card-head"><h3>Onboard new customer</h3></div>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>Full name<input name="full_name" required></label>
    <label>NIC number<input name="nic" required placeholder="199012345678 or 901234567V"></label>
    <label>Mobile<input name="phone" placeholder="0771234567"></label>
    <label>Email<input name="email" type="email" placeholder="name@example.lk"></label>
    <label>Home branch<select name="branch"><?php foreach ($branches as $b): ?><option<?= $b === $u['branch'] ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Create customer</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3><?= $search !== '' ? count($list) . ' result(s) for “' . e($search) . '”' : 'All customers (' . count($list) . ')' ?></h3></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>CIF</th><th>Name</th><th>NIC</th><th>Mobile</th><th>Branch</th><th class="num">Accounts</th><th class="num">Total balance</th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr class="clickable" data-href="customers.php?id=<?= (int)$c['id'] ?>">
        <td><code><?= e($c['cif']) ?></code></td><td><b><?= e($c['full_name']) ?></b></td><td><?= e($c['nic']) ?></td>
        <td><?= e($c['phone']) ?></td><td><?= e($c['branch']) ?></td><td class="num"><?= (int)$c['n'] ?></td><td class="num"><?= e(money((int)$c['bal'])) ?></td>
      </tr>
    <?php endforeach; if (!$list): ?><tr><td colspan="7" class="muted center">No customers found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
