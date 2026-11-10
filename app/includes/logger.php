<?php
/**
 * SB-FMS logging.
 *
 * Every security / business event is written two ways:
 *  1. Application log file  -> LOG_DIR\sbfms-YYYYMMDD.log  (syslog-style header + key=value body)
 *  2. IIS W3C log (native)  -> the event is placed in X-SB-* response headers, which IIS records
 *                              through the site's custom log fields (x-sb-event, x-sb-user, ...).
 * Both carry the same request id (x-sb-reqid / req_id) so the two sources can be correlated.
 */
declare(strict_types=1);

define('SB_REQ_ID', bin2hex(random_bytes(6)));
$GLOBALS['SB_EVENTS'] = [];

const SB_SEVERITY_RANK = ['INFO' => 1, 'NOTICE' => 2, 'WARN' => 3, 'HIGH' => 4, 'CRITICAL' => 5];

function sb_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

/** Format one value for the key=value log body. */
function sb_kv_value($v): string
{
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null || $v === '') return '-';
    $s = (string)$v;
    if (preg_match('/^[A-Za-z0-9_.:\/@+,\-]+$/', $s)) return $s;
    $s = str_replace(["\\", '"', "\r", "\n", "\t"], ["\\\\", '\\"', '\\r', '\\n', '\\t'], $s);
    return '"' . $s . '"';
}

/**
 * Log an application event.
 *
 * @param string $event    dotted event name, e.g. auth.login.failure
 * @param string $result   success | failure | denied
 * @param array  $ctx      extra fields; 'user' overrides the session user (used for login attempts)
 * @param string $severity INFO | NOTICE | WARN | HIGH | CRITICAL
 */
function sb_log(string $event, string $result = 'success', array $ctx = [], string $severity = 'INFO'): void
{
    $sessUser = $_SESSION['user'] ?? null;
    $user = array_key_exists('user', $ctx) ? (string)$ctx['user'] : ($sessUser['username'] ?? '-');
    $role = $sessUser['role'] ?? '-';
    unset($ctx['user']);
    if ($user === '') $user = '-';

    $sid = session_id() ? substr(hash('sha256', session_id()), 0, 12) : '-';
    $now = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
    $now->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $ts = $now->format('Y-m-d\TH:i:s.vP');

    $fields = [
        'severity' => $severity,
        'event'    => $event,
        'result'   => $result,
        'user'     => $user,
        'role'     => $role,
        'src_ip'   => sb_client_ip(),
        'req_id'   => SB_REQ_ID,
        'session'  => $sid,
        'method'   => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'uri'      => $_SERVER['SCRIPT_NAME'] ?? '-',
    ] + $ctx;
    $fields['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '-';

    $pairs = [];
    foreach ($fields as $k => $v) {
        $pairs[] = $k . '=' . sb_kv_value($v);
    }
    $line = sprintf('%s %s SBFMS[%d]: %s', $ts, gethostname() ?: 'localhost', getmypid(), implode(' ', $pairs)) . PHP_EOL;

    // 1) application log file
    try {
        if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0775, true);
        $file = LOG_DIR . DIRECTORY_SEPARATOR . 'sbfms-' . $now->format('Ymd') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('SBFMS: cannot write app log ' . $file);
        }
    } catch (Throwable $t) {
        error_log('SBFMS: logging error ' . $t->getMessage());
    }

    // 2) IIS native log via response headers
    $detail = [];
    foreach ($ctx as $k => $v) {
        $detail[] = $k . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
    }
    $GLOBALS['SB_EVENTS'][] = [
        'event' => $event, 'result' => $result, 'severity' => $severity,
        'user' => $user, 'role' => $role, 'detail' => implode(';', $detail),
    ];
    sb_emit_headers();
}

/** (Re)write the X-SB-* headers that IIS captures into its W3C log. */
function sb_emit_headers(): void
{
    if (headers_sent() || empty($GLOBALS['SB_EVENTS'])) return;
    $ev = $GLOBALS['SB_EVENTS'];
    $last = $ev[count($ev) - 1];
    $sev = 'INFO';
    foreach ($ev as $e) {
        if ((SB_SEVERITY_RANK[$e['severity']] ?? 0) > (SB_SEVERITY_RANK[$sev] ?? 0)) $sev = $e['severity'];
    }
    $clean = static fn(string $s): string => substr(preg_replace('/[\r\n\s]+/', '', $s), 0, 1500);
    header('X-SB-ReqId: ' . SB_REQ_ID);
    header('X-SB-Event: ' . $clean(implode(',', array_column($ev, 'event'))));
    header('X-SB-Result: ' . $clean(implode(',', array_column($ev, 'result'))));
    header('X-SB-Severity: ' . $sev);
    header('X-SB-User: ' . $clean(rawurlencode($last['user'])));
    header('X-SB-Role: ' . $clean($last['role']));
    $d = implode('|', array_filter(array_column($ev, 'detail')));
    header('X-SB-Detail: ' . ($d === '' ? '-' : $clean($d)));
}

/** Path of the app log file for a given day (Ymd). */
function sb_log_file(string $ymd): string
{
    return LOG_DIR . DIRECTORY_SEPARATOR . 'sbfms-' . $ymd . '.log';
}

/** Parse one app log line back into an array (used by the Audit page). */
function sb_parse_line(string $line): ?array
{
    if (!preg_match('/^(\S+)\s+(\S+)\s+SBFMS\[(\d+)\]:\s+(.*)$/', trim($line), $m)) return null;
    $out = ['ts' => $m[1], 'host' => $m[2], 'pid' => $m[3]];
    preg_match_all('/(\w+)=("(?:[^"\\\\]|\\\\.)*"|\S+)/', $m[4], $kv, PREG_SET_ORDER);
    foreach ($kv as $p) {
        $v = $p[2];
        if ($v !== '' && $v[0] === '"') $v = stripcslashes(substr($v, 1, -1));
        $out[$p[1]] = $v;
    }
    return $out;
}
