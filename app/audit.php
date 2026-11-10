<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_perm('audit.view');

$day = get('day', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');
$fSev = get('severity'); $fText = get('q');
$file = sb_log_file(str_replace('-', '', $day));

sb_log('audit.view', 'success', ['log_day' => $day, 'filter' => $fText ?: '-', 'severity_filter' => $fSev ?: '-'], 'NOTICE');

$rows = [];
$total = 0;
$counts = ['INFO' => 0, 'NOTICE' => 0, 'WARN' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
if (is_file($file) && is_readable($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $total = count($lines);
    foreach (array_reverse($lines) as $ln) {
        $r = sb_parse_line($ln);
        if (!$r) continue;
        $sev = $r['severity'] ?? 'INFO';
        if (isset($counts[$sev])) $counts[$sev]++;
        if ($fSev !== '' && $sev !== $fSev) continue;
        if ($fText !== '' && stripos($ln, $fText) === false) continue;
        if (count($rows) < 300) $rows[] = $r;
    }
}
$skip = ['ts', 'host', 'pid', 'severity', 'event', 'result', 'user', 'role', 'src_ip', 'req_id', 'session', 'method', 'uri', 'ua'];

render_header('Audit Logs', 'audit');
?>
<div class="grid-2 grid-audit">
  <div class="card">
    <div class="card-head"><h3>Log sources</h3></div>
    <div class="kv-list">
      <div><span>Application log</span><code><?= e($file) ?></code></div>
      <div><span>IIS W3C log</span><code><?= e(IIS_LOG_DIR) ?>\W3SVC&lt;site id&gt;\u_ex*.log</code></div>
      <div><span>IIS custom fields</span><code>x-sb-reqid x-sb-event x-sb-result x-sb-severity x-sb-user x-sb-role x-sb-detail</code></div>
      <div><span>Correlation</span><code>req_id (app) = x-sb-reqid (IIS)</code></div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Events on <?= e(date('d M Y', strtotime($day))) ?></h3></div>
    <div class="sev-tiles">
      <?php foreach ($counts as $s => $n): ?>
        <a class="sev sev-<?= strtolower($s) ?><?= $fSev === $s ? ' on' : '' ?>" href="?day=<?= e($day) ?>&severity=<?= $fSev === $s ? '' : e($s) ?>"><b><?= $n ?></b><span><?= e($s) ?></span></a>
      <?php endforeach; ?>
    </div>
    <p class="muted small"><?= $total ?> lines in file. Showing newest first (max 300).</p>
  </div>
</div>

<div class="toolbar">
  <form class="search" method="get">
    <input type="date" name="day" value="<?= e($day) ?>">
    <?= icon('search') ?><input name="q" value="<?= e($fText) ?>" placeholder="Filter: event, user, IP, account…">
    <select name="severity"><option value="">All severities</option><?php foreach (array_keys($counts) as $s): ?><option<?= $s === $fSev ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Apply</button>
  </form>
</div>

<div class="card">
  <div class="table-wrap"><table class="table log-table">
    <thead><tr><th>Time</th><th>Severity</th><th>Event</th><th>Result</th><th>User</th><th>Source IP</th><th>Details</th><th>Req ID</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $extra = array_diff_key($r, array_flip($skip)); ?>
      <tr>
        <td class="nowrap"><?= e(substr($r['ts'], 11, 12)) ?></td>
        <td><?= badge($r['severity'] ?? 'INFO', ['INFO' => 'muted', 'NOTICE' => 'navy', 'WARN' => 'warn', 'HIGH' => 'bad', 'CRITICAL' => 'bad'][$r['severity'] ?? 'INFO'] ?? 'muted') ?></td>
        <td><code><?= e($r['event'] ?? '') ?></code></td>
        <td><?= badge($r['result'] ?? '-') ?></td>
        <td><?= e($r['user'] ?? '-') ?><?= ($r['role'] ?? '-') !== '-' ? ' <span class="muted">' . e($r['role']) . '</span>' : '' ?></td>
        <td><?= e($r['src_ip'] ?? '-') ?></td>
        <td class="details"><?php foreach ($extra as $k => $v): ?><span class="kv"><b><?= e($k) ?></b>=<?= e($v) ?></span><?php endforeach; ?></td>
        <td><code class="small"><?= e($r['req_id'] ?? '') ?></code></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="muted center"><?= is_file($file) ? 'No events match the filter.' : 'No log file for this day.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render_footer();
