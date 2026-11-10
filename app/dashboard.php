<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();

$totalDeposits = (int)q("SELECT COALESCE(SUM(balance),0) FROM accounts WHERE status <> 'closed'")->fetchColumn();
$custCount     = (int)q('SELECT COUNT(*) FROM customers')->fetchColumn();
$acctCount     = (int)q("SELECT COUNT(*) FROM accounts WHERE status = 'active'")->fetchColumn();
$frozenCount   = (int)q("SELECT COUNT(*) FROM accounts WHERE status = 'frozen'")->fetchColumn();
$today         = q("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM transactions WHERE date(created_at) = date('now','localtime') AND type IN ('DEPOSIT','WITHDRAWAL','TRANSFER_OUT')")->fetch();
$flagged       = (int)q("SELECT COUNT(DISTINCT ref) FROM transactions WHERE flagged = 1 AND created_at >= datetime('now','localtime','-30 days')")->fetchColumn();

// last 14 days inflow vs outflow
$rows = q("SELECT date(created_at) d,
             SUM(CASE WHEN type IN ('DEPOSIT') THEN amount ELSE 0 END) inflow,
             SUM(CASE WHEN type IN ('WITHDRAWAL') THEN amount ELSE 0 END) outflow,
             SUM(CASE WHEN type = 'TRANSFER_OUT' THEN amount ELSE 0 END) xfer
           FROM transactions WHERE created_at >= date('now','localtime','-13 days') GROUP BY d")->fetchAll();
$byDay = [];
foreach ($rows as $r) $byDay[$r['d']] = $r;
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[] = ['d' => $d, 'in' => (int)($byDay[$d]['inflow'] ?? 0), 'out' => (int)($byDay[$d]['outflow'] ?? 0), 'x' => (int)($byDay[$d]['xfer'] ?? 0)];
}
$max = max(1, ...array_map(fn($x) => max($x['in'], $x['out'], $x['x']), $days));

$mix = q("SELECT type, COUNT(*) n, SUM(balance) b FROM accounts WHERE status <> 'closed' GROUP BY type ORDER BY b DESC")->fetchAll();
$mixTotal = max(1, array_sum(array_column($mix, 'b')));

$recent = q("SELECT t.*, a.account_no, c.full_name FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN customers c ON c.id = a.customer_id
             WHERE t.type <> 'TRANSFER_IN' ORDER BY t.id DESC LIMIT 8")->fetchAll();

render_header('Dashboard', 'dashboard');
?>
<div class="welcome card">
  <div>
    <h2>Good <?= (int)date('G') < 12 ? 'morning' : ((int)date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $u['full_name'])[0]) ?></h2>
    <p class="muted">Here is today's position across Senarath Bank branches.</p>
  </div>
  <div class="welcome-actions">
    <?php if (can('txn.post')): ?><a class="btn btn-primary" href="transactions.php?tab=deposit"><?= icon('plus') ?> New transaction</a><?php endif; ?>
    <?php if (can('customer.create')): ?><a class="btn" href="customers.php?new=1"><?= icon('users') ?> New customer</a><?php endif; ?>
  </div>
</div>

<div class="kpis">
  <div class="kpi card"><div class="kpi-ic"><?= icon('wallet') ?></div><span>Total deposits held</span><b><?= e(money_short($totalDeposits)) ?></b><small><?= e(money($totalDeposits)) ?></small></div>
  <div class="kpi card"><div class="kpi-ic"><?= icon('users') ?></div><span>Customers</span><b><?= $custCount ?></b><small><?= $acctCount ?> active accounts</small></div>
  <div class="kpi card"><div class="kpi-ic"><?= icon('swap') ?></div><span>Transactions today</span><b><?= (int)$today['c'] ?></b><small><?= e(money((int)$today['s'])) ?> moved</small></div>
  <div class="kpi card kpi-alert"><div class="kpi-ic"><?= icon('alert') ?></div><span>High-value flags (30d)</span><b><?= $flagged ?></b><small><?= $frozenCount ?> frozen accounts</small></div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h3>Cash flow · last 14 days</h3>
      <div class="legend"><span class="lg lg-in"></span>Deposits <span class="lg lg-out"></span>Withdrawals <span class="lg lg-x"></span>Transfers</div></div>
    <svg class="chart" viewBox="0 0 700 240" preserveAspectRatio="none" role="img" aria-label="Cash flow chart">
      <?php for ($g = 0; $g <= 4; $g++): $y = 20 + $g * 50; ?>
        <line x1="40" x2="690" y1="<?= $y ?>" y2="<?= $y ?>" class="grid-line"/>
        <text x="34" y="<?= $y + 4 ?>" class="axis" text-anchor="end"><?= e(str_replace('LKR ', '', money_short((int)($max * (4 - $g) / 4)))) ?></text>
      <?php endfor; ?>
      <?php foreach ($days as $i => $d):
          $x0 = 48 + $i * 46.3; $bw = 12;
          $h1 = 200 * $d['in'] / $max; $h2 = 200 * $d['out'] / $max; $h3 = 200 * $d['x'] / $max; ?>
        <rect x="<?= $x0 ?>" y="<?= 220 - $h1 ?>" width="<?= $bw ?>" height="<?= max(0, $h1) ?>" rx="2" class="bar-in"><title><?= e($d['d'] . ' deposits ' . money($d['in'])) ?></title></rect>
        <rect x="<?= $x0 + $bw + 1 ?>" y="<?= 220 - $h2 ?>" width="<?= $bw ?>" height="<?= max(0, $h2) ?>" rx="2" class="bar-out"><title><?= e($d['d'] . ' withdrawals ' . money($d['out'])) ?></title></rect>
        <rect x="<?= $x0 + 2 * $bw + 2 ?>" y="<?= 220 - $h3 ?>" width="<?= $bw ?>" height="<?= max(0, $h3) ?>" rx="2" class="bar-x"><title><?= e($d['d'] . ' transfers ' . money($d['x'])) ?></title></rect>
        <?php if ($i % 2 === 0): ?><text x="<?= $x0 + 19 ?>" y="236" class="axis" text-anchor="middle"><?= e(date('d M', strtotime($d['d']))) ?></text><?php endif; ?>
      <?php endforeach; ?>
    </svg>
  </div>

  <div class="card">
    <div class="card-head"><h3>Deposit mix</h3></div>
    <div class="mix">
      <?php foreach ($mix as $m): $pct = round(100 * (int)$m['b'] / $mixTotal, 1); ?>
        <div class="mix-row">
          <div class="mix-label"><b><?= e($m['type']) ?></b><span><?= (int)$m['n'] ?> accounts · <?= e(money_short((int)$m['b'])) ?></span></div>
          <div class="meter"><span style="width: <?= $pct ?>%"></span></div>
          <div class="mix-pct"><?= $pct ?>%</div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="note"><?= icon('shield') ?> Teller limit <?= e(money(TELLER_TXN_LIMIT)) ?> per transaction · amounts ≥ <?= e(money(HIGH_VALUE_THRESHOLD)) ?> are flagged for review.</div>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Recent transactions</h3><a class="link" href="transactions.php">View all →</a></div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Reference</th><th>Date</th><th>Customer</th><th>Account</th><th>Type</th><th class="num">Amount</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $t): ?>
      <tr<?= $t['flagged'] ? ' class="row-flag"' : '' ?>>
        <td><code><?= e($t['ref']) ?></code></td>
        <td><?= e(date('d M Y H:i', strtotime($t['created_at']))) ?></td>
        <td><?= e($t['full_name']) ?></td>
        <td><a href="accounts.php?id=<?= (int)$t['account_id'] ?>"><?= e($t['account_no']) ?></a></td>
        <td><?= badge($t['type']) ?><?= $t['flagged'] ? ' ' . badge('HIGH VALUE', 'bad') : '' ?></td>
        <td class="num"><?= e(money((int)$t['amount'])) ?></td>
        <td><?= e($t['performed_by']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php render_footer();
