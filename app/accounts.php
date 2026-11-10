<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
$types = ['Savings', 'Current', 'Fixed Deposit'];

if (is_post()) {
    $action = post('action');

    // ---- open account -------------------------------------------------------
    if ($action === 'open') {
        require_perm('account.open');
        csrf_check();
        $cid = (int)post('customer_id'); $type = post('type'); $initial = post('initial') === '' ? 0 : parse_amount(post('initial'));
        $c = q('SELECT * FROM customers WHERE id = ?', [$cid])->fetch();
        if (!$c || !in_array($type, $types, true) || $initial === null) {
            sb_log('account.open', 'failure', ['customer_id' => $cid, 'reason' => 'validation'], 'INFO');
            flash('error', 'Select a customer, an account type and a valid opening amount.');
            redirect('accounts.php?new=1');
        }
        if ($initial > TELLER_TXN_LIMIT && !can('txn.high_value')) {
            sb_log('account.open', 'denied', ['customer_id' => $cid, 'amount' => $initial / 100, 'reason' => 'teller_limit_exceeded'], 'WARN');
            flash('error', 'Opening deposit exceeds your teller limit of ' . money(TELLER_TXN_LIMIT) . '. Ask a manager.');
            redirect('accounts.php?new=1&customer_id=' . $cid);
        }
        $seq = 1 + (int)q('SELECT COUNT(*) FROM accounts')->fetchColumn();
        do { $no = '1001' . str_pad((string)$seq++, 8, '0', STR_PAD_LEFT); } while (q('SELECT 1 FROM accounts WHERE account_no = ?', [$no])->fetchColumn());
        db()->beginTransaction();
        q('INSERT INTO accounts (account_no, customer_id, type, balance, branch) VALUES (?,?,?,?,?)', [$no, $cid, $type, $initial, $u['branch'] === 'Head Office' ? $c['branch'] : $u['branch']]);
        $aid = (int)db()->lastInsertId();
        if ($initial > 0) {
            q('INSERT INTO transactions (ref, account_id, type, amount, balance_after, narration, performed_by, flagged) VALUES (?,?,?,?,?,?,?,?)',
              [sb_txn_ref(), $aid, 'DEPOSIT', $initial, $initial, 'Opening deposit', $u['username'], $initial >= HIGH_VALUE_THRESHOLD ? 1 : 0]);
        }
        db()->commit();
        sb_log('account.open', 'success', ['account_no' => $no, 'cif' => $c['cif'], 'type' => $type, 'amount' => $initial / 100], 'INFO');
        flash('success', "$type account $no opened for {$c['full_name']}.");
        redirect('accounts.php?id=' . $aid);
    }

    // ---- freeze / unfreeze --------------------------------------------------
    if ($action === 'freeze' || $action === 'unfreeze') {
        require_perm('account.freeze');
        csrf_check();
        $aid = (int)post('account_id'); $reason = substr(post('reason'), 0, 120);
        $a = q('SELECT * FROM accounts WHERE id = ?', [$aid])->fetch();
        if (!$a) { flash('error', 'Account not found.'); redirect('accounts.php'); }
        $new = $action === 'freeze' ? 'frozen' : 'active';
        q('UPDATE accounts SET status = ? WHERE id = ?', [$new, $aid]);
        sb_log('account.' . $action, 'success', ['account_no' => $a['account_no'], 'reason' => $reason ?: '-'], 'WARN');
        flash('success', "Account {$a['account_no']} is now $new.");
        redirect('accounts.php?id=' . $aid);
    }
}

// ---- account statement --------------------------------------------------------
$id = (int)get('id', '0');
if ($id > 0) {
    $a = q('SELECT a.*, c.full_name, c.cif, c.id cid FROM accounts a JOIN customers c ON c.id = a.customer_id WHERE a.id = ?', [$id])->fetch();
    if (!$a) { http_response_code(404); flash('error', 'Account not found.'); redirect('accounts.php'); }
    sb_log('account.view', 'success', ['account_no' => $a['account_no'], 'cif' => $a['cif']], 'INFO');
    $txns = q('SELECT * FROM transactions WHERE account_id = ? ORDER BY id DESC LIMIT 100', [$id])->fetchAll();
    render_header('Account ' . $a['account_no'], 'accounts');
    ?>
    <div class="acct-hero card">
      <div>
        <span class="eyebrow"><?= e($a['type']) ?> account · <?= e($a['branch']) ?></span>
        <h2 class="acct-no"><?= e(implode(' ', str_split($a['account_no'], 4))) ?></h2>
        <p><a href="customers.php?id=<?= (int)$a['cid'] ?>"><?= e($a['full_name']) ?></a> · <?= e($a['cif']) ?> · opened <?= e(date('d M Y', strtotime($a['opened_at']))) ?></p>
      </div>
      <div class="acct-bal"><span>Available balance</span><b><?= e(money((int)$a['balance'])) ?></b><?= badge($a['status']) ?></div>
    </div>
    <div class="toolbar">
      <div class="btn-row">
        <?php if (can('txn.post') && $a['status'] === 'active'): ?>
          <a class="btn btn-primary" href="transactions.php?tab=deposit&account=<?= e($a['account_no']) ?>">Deposit</a>
          <a class="btn" href="transactions.php?tab=withdraw&account=<?= e($a['account_no']) ?>">Withdraw</a>
          <a class="btn" href="transactions.php?tab=transfer&account=<?= e($a['account_no']) ?>">Transfer</a>
        <?php endif; ?>
      </div>
      <?php if (can('account.freeze')): ?>
        <form method="post" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="account_id" value="<?= $id ?>">
          <input name="reason" placeholder="Reason (optional)">
          <?php if ($a['status'] === 'active'): ?>
            <button class="btn btn-danger" name="action" value="freeze" type="submit" data-confirm="Freeze this account?"><?= icon('lock') ?> Freeze</button>
          <?php elseif ($a['status'] === 'frozen'): ?>
            <button class="btn" name="action" value="unfreeze" type="submit">Unfreeze</button>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Statement (last 100)</h3></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Date</th><th>Reference</th><th>Narration</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t): $debit = in_array($t['type'], ['WITHDRAWAL', 'TRANSFER_OUT'], true); ?>
          <tr<?= $t['flagged'] ? ' class="row-flag"' : '' ?>>
            <td><?= e(date('d M Y H:i', strtotime($t['created_at']))) ?></td><td><code><?= e($t['ref']) ?></code></td>
            <td><?= e($t['narration']) ?><?= $t['counterparty'] ? ' <span class="muted">· ' . e($t['counterparty']) . '</span>' : '' ?></td>
            <td><?= badge($t['type']) ?></td>
            <td class="num neg"><?= $debit ? e(money((int)$t['amount'], false)) : '' ?></td>
            <td class="num pos"><?= !$debit ? e(money((int)$t['amount'], false)) : '' ?></td>
            <td class="num"><?= e(money((int)$t['balance_after'], false)) ?></td><td><?= e($t['performed_by']) ?></td>
          </tr>
        <?php endforeach; if (!$txns): ?><tr><td colspan="8" class="muted center">No transactions.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <?php
    render_footer(); exit;
}

// ---- list -------------------------------------------------------------------------
$fType = get('type'); $fStatus = get('status'); $fq = get('q');
$where = []; $args = [];
if (in_array($fType, $types, true)) { $where[] = 'a.type = ?'; $args[] = $fType; }
if (in_array($fStatus, ['active', 'frozen', 'closed'], true)) { $where[] = 'a.status = ?'; $args[] = $fStatus; }
if ($fq !== '') { $where[] = '(a.account_no LIKE ? OR c.full_name LIKE ?)'; $args[] = "%$fq%"; $args[] = "%$fq%"; }
$list = q('SELECT a.*, c.full_name, c.cif FROM accounts a JOIN customers c ON c.id = a.customer_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.id DESC', $args)->fetchAll();
$customers = q('SELECT id, cif, full_name FROM customers ORDER BY full_name')->fetchAll();

render_header('Accounts', 'accounts');
?>
<div class="toolbar">
  <form class="search" method="get">
    <?= icon('search') ?><input name="q" value="<?= e($fq) ?>" placeholder="Account number or customer">
    <select name="type"><option value="">All types</option><?php foreach ($types as $t): ?><option<?= $t === $fType ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">Any status</option><?php foreach (['active', 'frozen', 'closed'] as $s): ?><option<?= $s === $fStatus ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filter</button>
  </form>
  <?php if (can('account.open')): ?><button class="btn btn-primary" type="button" data-toggle="new-account"><?= icon('plus') ?> Open account</button><?php endif; ?>
</div>

<?php if (can('account.open')): ?>
<div class="card panel<?= get('new') ? ' open' : '' ?>" id="new-account">
  <div class="card-head"><h3>Open a new account</h3></div>
  <form method="post" class="form form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="open">
    <label>Customer<select name="customer_id" required><option value="">Select customer…</option>
      <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)get('customer_id') === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['full_name'] . ' — ' . $c['cif']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Account type<select name="type"><?php foreach ($types as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></label>
    <label>Opening deposit (LKR)<input name="initial" inputmode="decimal" placeholder="0.00"></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Open account</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3><?= count($list) ?> account(s)</h3></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Account no.</th><th>Customer</th><th>Type</th><th>Branch</th><th>Status</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($list as $a): ?>
      <tr class="clickable" data-href="accounts.php?id=<?= (int)$a['id'] ?>">
        <td><code><?= e($a['account_no']) ?></code></td><td><?= e($a['full_name']) ?> <span class="muted"><?= e($a['cif']) ?></span></td>
        <td><?= e($a['type']) ?></td><td><?= e($a['branch']) ?></td><td><?= badge($a['status']) ?></td><td class="num"><?= e(money((int)$a['balance'])) ?></td>
      </tr>
    <?php endforeach; if (!$list): ?><tr><td colspan="6" class="muted center">No accounts match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
