<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

/** Validate and post a single-account cash transaction. Returns [ok, message]. */
function sb_cash_txn(array $u, string $kind, string $acctNo, int $amount, string $narr): array
{
    $evt = $kind === 'deposit' ? 'txn.deposit' : 'txn.withdrawal';
    $a = q('SELECT * FROM accounts WHERE account_no = ?', [$acctNo])->fetch();
    if (!$a) {
        sb_log($evt, 'failure', ['account_no' => $acctNo, 'amount' => $amount / 100, 'reason' => 'invalid_account'], 'INFO');
        return [false, 'Account number not found.'];
    }
    if ($a['status'] !== 'active') {
        sb_log($evt, 'denied', ['account_no' => $acctNo, 'amount' => $amount / 100, 'reason' => 'account_' . $a['status']], 'WARN');
        return [false, "Account is {$a['status']}; transactions are blocked."];
    }
    if ($amount > TELLER_TXN_LIMIT && !can('txn.high_value')) {
        sb_log($evt, 'denied', ['account_no' => $acctNo, 'amount' => $amount / 100, 'reason' => 'teller_limit_exceeded', 'limit' => TELLER_TXN_LIMIT / 100], 'WARN');
        return [false, 'Amount exceeds your teller limit of ' . money(TELLER_TXN_LIMIT) . '. Manager authorisation required.'];
    }
    if ($kind === 'withdraw' && $a['balance'] < $amount) {
        sb_log($evt, 'denied', ['account_no' => $acctNo, 'amount' => $amount / 100, 'reason' => 'insufficient_funds'], 'INFO');
        return [false, 'Insufficient funds. Available: ' . money((int)$a['balance'])];
    }
    $new = $kind === 'deposit' ? $a['balance'] + $amount : $a['balance'] - $amount;
    $flag = $amount >= HIGH_VALUE_THRESHOLD ? 1 : 0;
    $ref = sb_txn_ref();
    db()->beginTransaction();
    q('UPDATE accounts SET balance = ? WHERE id = ?', [$new, $a['id']]);
    q('INSERT INTO transactions (ref, account_id, type, amount, balance_after, narration, performed_by, flagged) VALUES (?,?,?,?,?,?,?,?)',
      [$ref, $a['id'], $kind === 'deposit' ? 'DEPOSIT' : 'WITHDRAWAL', $amount, $new, $narr ?: ($kind === 'deposit' ? 'Cash deposit' : 'Cash withdrawal'), $u['username'], $flag]);
    db()->commit();
    sb_log($evt, 'success', ['txn_ref' => $ref, 'account_no' => $acctNo, 'amount' => $amount / 100, 'currency' => 'LKR', 'balance_after' => $new / 100], 'INFO');
    if ($flag) sb_log('txn.high_value', 'success', ['txn_ref' => $ref, 'account_no' => $acctNo, 'amount' => $amount / 100, 'threshold' => HIGH_VALUE_THRESHOLD / 100], 'WARN');
    return [true, ucfirst($kind) . ' of ' . money($amount) . " posted. Ref $ref."];
}

if (is_post()) {
    require_perm('txn.post');
    csrf_check();
    $action = post('action');
    $amount = parse_amount(post('amount'));
    $narr = substr(post('narration'), 0, 120);

    if ($amount === null) {
        sb_log('txn.' . ($action ?: 'unknown'), 'failure', ['reason' => 'invalid_amount', 'raw_amount' => substr(post('amount'), 0, 20)], 'INFO');
        flash('error', 'Enter a valid amount (e.g. 25000.00).');
        redirect('transactions.php?tab=' . urlencode($action));
    }

    if ($action === 'deposit' || $action === 'withdraw') {
        [$ok, $msg] = sb_cash_txn($u, $action, post('account'), $amount, $narr);
        flash($ok ? 'success' : 'error', $msg);
        redirect('transactions.php?tab=' . $action);
    }

    if ($action === 'transfer') {
        $fromNo = post('from'); $toNo = post('to');
        $from = q('SELECT * FROM accounts WHERE account_no = ?', [$fromNo])->fetch();
        $to   = q('SELECT * FROM accounts WHERE account_no = ?', [$toNo])->fetch();
        $base = ['from_account' => $fromNo, 'to_account' => $toNo, 'amount' => $amount / 100];
        $fail = null; $sev = 'INFO'; $res = 'failure';
        if (!$from || !$to)                          { $fail = ['invalid_account', 'One of the account numbers was not found.']; }
        elseif ($from['id'] === $to['id'])           { $fail = ['same_account', 'Source and destination must differ.']; }
        elseif ($from['status'] !== 'active' || $to['status'] !== 'active') { $fail = ['account_not_active', 'Both accounts must be active.']; $sev = 'WARN'; $res = 'denied'; }
        elseif ($amount > TELLER_TXN_LIMIT && !can('txn.high_value')) { $fail = ['teller_limit_exceeded', 'Amount exceeds your teller limit of ' . money(TELLER_TXN_LIMIT) . '.']; $sev = 'WARN'; $res = 'denied'; }
        elseif ($from['balance'] < $amount)          { $fail = ['insufficient_funds', 'Insufficient funds in source account.']; $res = 'denied'; }

        if ($fail) {
            sb_log('txn.transfer', $res, $base + ['reason' => $fail[0]], $sev);
            flash('error', $fail[1]);
            redirect('transactions.php?tab=transfer');
        }
        $ref = sb_txn_ref();
        $flag = $amount >= HIGH_VALUE_THRESHOLD ? 1 : 0;
        db()->beginTransaction();
        $fb = $from['balance'] - $amount; $tb = $to['balance'] + $amount;
        q('UPDATE accounts SET balance = ? WHERE id = ?', [$fb, $from['id']]);
        q('UPDATE accounts SET balance = ? WHERE id = ?', [$tb, $to['id']]);
        $ins = 'INSERT INTO transactions (ref, account_id, type, amount, balance_after, counterparty, narration, performed_by, flagged) VALUES (?,?,?,?,?,?,?,?,?)';
        q($ins, [$ref, $from['id'], 'TRANSFER_OUT', $amount, $fb, $toNo, $narr ?: 'Fund transfer', $u['username'], $flag]);
        q($ins, [$ref, $to['id'], 'TRANSFER_IN', $amount, $tb, $fromNo, $narr ?: 'Fund transfer', $u['username'], $flag]);
        db()->commit();
        sb_log('txn.transfer', 'success', ['txn_ref' => $ref] + $base + ['currency' => 'LKR'], 'INFO');
        if ($flag) sb_log('txn.high_value', 'success', ['txn_ref' => $ref, 'account_no' => $fromNo, 'amount' => $amount / 100, 'threshold' => HIGH_VALUE_THRESHOLD / 100], 'WARN');
        flash('success', 'Transfer of ' . money($amount) . " from $fromNo to $toNo completed. Ref $ref.");
        redirect('transactions.php?tab=transfer');
    }
    flash('error', 'Unknown action.');
    redirect('transactions.php');
}

// ---- list ----------------------------------------------------------------------
$tab = get('tab', can('txn.post') ? 'deposit' : '');
$fType = get('type'); $fFlag = get('flagged'); $fFrom = get('from'); $fTo = get('to');
$where = []; $args = [];
if (in_array($fType, ['DEPOSIT', 'WITHDRAWAL', 'TRANSFER_IN', 'TRANSFER_OUT'], true)) { $where[] = 't.type = ?'; $args[] = $fType; }
if ($fFlag === '1') $where[] = 't.flagged = 1';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[] = 'date(t.created_at) >= ?'; $args[] = $fFrom; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) { $where[] = 'date(t.created_at) <= ?'; $args[] = $fTo; }
$list = q('SELECT t.*, a.account_no, c.full_name FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN customers c ON c.id = a.customer_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.id DESC LIMIT 200', $args)->fetchAll();
$prefill = get('account');

render_header('Transactions', 'transactions');
?>
<?php if (can('txn.post')): ?>
<div class="card">
  <div class="tabs">
    <a class="tab<?= $tab === 'deposit' ? ' active' : '' ?>" href="?tab=deposit">Deposit</a>
    <a class="tab<?= $tab === 'withdraw' ? ' active' : '' ?>" href="?tab=withdraw">Withdrawal</a>
    <a class="tab<?= $tab === 'transfer' ? ' active' : '' ?>" href="?tab=transfer">Fund transfer</a>
  </div>
  <?php if ($tab === 'transfer'): ?>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="transfer">
      <label>From account<input name="from" value="<?= e($prefill) ?>" required placeholder="100100000001" inputmode="numeric"></label>
      <label>To account<input name="to" required placeholder="100100000002" inputmode="numeric"></label>
      <label>Amount (LKR)<input name="amount" required inputmode="decimal" placeholder="0.00"></label>
      <label>Narration<input name="narration" placeholder="Fund transfer"></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit" data-confirm="Confirm fund transfer?">Transfer funds</button></div>
    </form>
  <?php else: $isDep = $tab !== 'withdraw'; ?>
    <form method="post" class="form form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="<?= $isDep ? 'deposit' : 'withdraw' ?>">
      <label>Account number<input name="account" value="<?= e($prefill) ?>" required placeholder="100100000001" inputmode="numeric"></label>
      <label>Amount (LKR)<input name="amount" required inputmode="decimal" placeholder="0.00"></label>
      <label>Narration<input name="narration" placeholder="<?= $isDep ? 'Cash deposit' : 'Cash withdrawal' ?>"></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $isDep ? 'Post deposit' : 'Post withdrawal' ?></button></div>
    </form>
  <?php endif; ?>
  <div class="note"><?= icon('shield') ?> Your role: <b><?= e($u['role']) ?></b>. <?= can('txn.high_value') ? 'You may authorise high-value transactions.' : 'Per-transaction limit ' . e(money(TELLER_TXN_LIMIT)) . '.' ?> Amounts ≥ <?= e(money(HIGH_VALUE_THRESHOLD)) ?> are flagged.</div>
</div>
<?php endif; ?>

<div class="toolbar">
  <form class="search" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <select name="type"><option value="">All types</option><?php foreach (['DEPOSIT', 'WITHDRAWAL', 'TRANSFER_IN', 'TRANSFER_OUT'] as $t): ?><option<?= $t === $fType ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($fFrom) ?>" title="From date">
    <input type="date" name="to" value="<?= e($fTo) ?>" title="To date">
    <label class="check"><input type="checkbox" name="flagged" value="1"<?= $fFlag === '1' ? ' checked' : '' ?>> High value only</label>
    <button class="btn" type="submit">Filter</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><h3>Transaction journal <span class="muted">(latest 200)</span></h3></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Reference</th><th>Account</th><th>Customer</th><th>Type</th><th>Narration</th><th class="num">Amount</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($list as $t): ?>
      <tr<?= $t['flagged'] ? ' class="row-flag"' : '' ?>>
        <td><?= e(date('d M Y H:i', strtotime($t['created_at']))) ?></td><td><code><?= e($t['ref']) ?></code></td>
        <td><a href="accounts.php?id=<?= (int)$t['account_id'] ?>"><?= e($t['account_no']) ?></a></td><td><?= e($t['full_name']) ?></td>
        <td><?= badge($t['type']) ?><?= $t['flagged'] ? ' ' . badge('HIGH VALUE', 'bad') : '' ?></td>
        <td><?= e($t['narration']) ?><?= $t['counterparty'] ? ' <span class="muted">· ' . e($t['counterparty']) . '</span>' : '' ?></td>
        <td class="num"><?= e(money((int)$t['amount'])) ?></td><td><?= e($t['performed_by']) ?></td>
      </tr>
    <?php endforeach; if (!$list): ?><tr><td colspan="8" class="muted center">No transactions match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
