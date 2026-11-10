<?php
require __DIR__ . '/includes/bootstrap.php';

$export = get('export');
if ($export !== '') {
    $u = require_perm('report.export');
    $date = date('Ymd_His');
    if ($export === 'transactions') {
        $rows = q("SELECT t.created_at, t.ref, a.account_no, c.cif, c.full_name, t.type, printf('%.2f', t.amount/100.0) amount_lkr,
                          printf('%.2f', t.balance_after/100.0) balance_after_lkr, t.counterparty, t.narration, t.performed_by, t.flagged
                   FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN customers c ON c.id = a.customer_id
                   WHERE t.created_at >= datetime('now','localtime','-30 days') ORDER BY t.id")->fetchAll();
        $sev = 'NOTICE';
    } elseif ($export === 'customers') {
        $rows = q('SELECT c.cif, c.full_name, c.nic, c.phone, c.email, c.branch, c.created_at,
                          (SELECT COUNT(*) FROM accounts a WHERE a.customer_id = c.id) accounts,
                          (SELECT printf(\'%.2f\', COALESCE(SUM(balance),0)/100.0) FROM accounts a WHERE a.customer_id = c.id) total_balance_lkr
                   FROM customers c ORDER BY c.cif')->fetchAll();
        $sev = 'WARN'; // bulk PII export
    } else {
        sb_log('report.export', 'failure', ['report' => substr($export, 0, 40), 'reason' => 'unknown_report'], 'INFO');
        flash('error', 'Unknown report.');
        redirect('reports.php');
    }
    $file = "sbfms_{$export}_{$date}.csv";
    sb_log('report.export', 'success', ['report' => $export, 'rows' => count($rows), 'file' => $file, 'pii' => $export === 'customers'], $sev);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($rows) fputcsv($out, array_keys($rows[0]), ',', '"', '\\');
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '\\');
    fclose($out);
    exit;
}

$u = require_perm('report.view');

$byBranch = q("SELECT a.branch, COUNT(DISTINCT a.customer_id) customers, COUNT(*) accounts, SUM(a.balance) bal,
                 SUM(CASE WHEN a.status = 'frozen' THEN 1 ELSE 0 END) frozen
               FROM accounts a WHERE a.status <> 'closed' GROUP BY a.branch ORDER BY bal DESC")->fetchAll();
$byType = q("SELECT type, COUNT(*) n, SUM(amount) s FROM transactions WHERE created_at >= datetime('now','localtime','-30 days') GROUP BY type ORDER BY s DESC")->fetchAll();
$byUser = q("SELECT performed_by, COUNT(*) n, SUM(amount) s, SUM(flagged) f FROM transactions
             WHERE created_at >= datetime('now','localtime','-30 days') AND type <> 'TRANSFER_IN' GROUP BY performed_by ORDER BY n DESC")->fetchAll();
$flagged = q("SELECT t.*, a.account_no, c.full_name FROM transactions t JOIN accounts a ON a.id = t.account_id JOIN customers c ON c.id = a.customer_id
              WHERE t.flagged = 1 AND t.type <> 'TRANSFER_IN' ORDER BY t.id DESC LIMIT 15")->fetchAll();
$maxBal = max(1, ...array_map(fn($r) => (int)$r['bal'], $byBranch ?: [['bal' => 1]]));

render_header('Reports', 'reports');
?>
<div class="toolbar">
  <p class="muted">Management information · data as of <?= e(date('d M Y H:i')) ?></p>
  <div class="btn-row">
    <a class="btn" href="reports.php?export=transactions"><?= icon('down') ?> Transactions CSV (30d)</a>
    <a class="btn" href="reports.php?export=customers" data-confirm="This export contains customer PII. Continue?"><?= icon('down') ?> Customer register CSV</a>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h3>Deposits by branch</h3></div>
    <div class="mix">
    <?php foreach ($byBranch as $b): ?>
      <div class="mix-row">
        <div class="mix-label"><b><?= e($b['branch']) ?></b><span><?= (int)$b['customers'] ?> customers · <?= (int)$b['accounts'] ?> accounts<?= $b['frozen'] ? ' · ' . (int)$b['frozen'] . ' frozen' : '' ?></span></div>
        <div class="meter"><span style="width: <?= round(100 * (int)$b['bal'] / $maxBal, 1) ?>%"></span></div>
        <div class="mix-pct"><?= e(money_short((int)$b['bal'])) ?></div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Volume by type · 30 days</h3></div>
    <table class="table compact">
      <thead><tr><th>Type</th><th class="num">Count</th><th class="num">Value</th></tr></thead>
      <tbody><?php foreach ($byType as $t): ?><tr><td><?= badge($t['type']) ?></td><td class="num"><?= (int)$t['n'] ?></td><td class="num"><?= e(money((int)$t['s'])) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <div class="card-head"><h3>Activity by officer · 30 days</h3></div>
    <table class="table compact">
      <thead><tr><th>Officer</th><th class="num">Txns</th><th class="num">Value</th><th class="num">Flags</th></tr></thead>
      <tbody><?php foreach ($byUser as $r): ?><tr><td><?= e($r['performed_by']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= e(money_short((int)$r['s'])) ?></td><td class="num"><?= (int)$r['f'] ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>High-value transactions for review</h3></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Reference</th><th>Account</th><th>Customer</th><th>Type</th><th class="num">Amount</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($flagged as $t): ?>
      <tr><td><?= e(date('d M Y H:i', strtotime($t['created_at']))) ?></td><td><code><?= e($t['ref']) ?></code></td><td><?= e($t['account_no']) ?></td>
      <td><?= e($t['full_name']) ?></td><td><?= badge($t['type']) ?></td><td class="num"><?= e(money((int)$t['amount'])) ?></td><td><?= e($t['performed_by']) ?></td></tr>
    <?php endforeach; if (!$flagged): ?><tr><td colspan="7" class="muted center">No flagged transactions.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
