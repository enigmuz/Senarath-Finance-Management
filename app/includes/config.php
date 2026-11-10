<?php
/**
 * Senarath Bank Finance Management System (SB-FMS) - configuration.
 * Override any value by creating includes/config.local.php (git-ignored) with define() calls.
 */
declare(strict_types=1);

const APP_NAME    = 'Senarath Bank Finance Management System';
const APP_SHORT   = 'SB-FMS';
const APP_VERSION = '1.0.0';

define('APP_ROOT', dirname(__DIR__));

$__local = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
if (is_file($__local)) {
    require $__local;
}

// SQLite database + PHP session files (inside the site, blocked from the web by web.config)
if (!defined('DATA_DIR')) define('DATA_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'data');
if (!defined('DB_FILE'))  define('DB_FILE', DATA_DIR . DIRECTORY_SEPARATOR . 'sbfms.sqlite');

// Application log folder (one file per day: sbfms-YYYYMMDD.log)
if (!defined('LOG_DIR')) {
    $envDir = getenv('SBFMS_LOG_DIR');
    define('LOG_DIR', $envDir ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\SenarathFMS_Logs\\app' : DATA_DIR . '/logs'));
}
// IIS W3C log folder (informational, shown on the Audit page)
if (!defined('IIS_LOG_DIR')) define('IIS_LOG_DIR', 'C:\\SenarathFMS_Logs\\IIS');

// Security / business rules (amounts are in cents)
if (!defined('SESSION_IDLE_SECONDS')) define('SESSION_IDLE_SECONDS', 900);        // 15 min idle timeout
if (!defined('MAX_FAILED_LOGINS'))    define('MAX_FAILED_LOGINS', 5);              // lock after 5 bad passwords
if (!defined('TELLER_TXN_LIMIT'))     define('TELLER_TXN_LIMIT', 50000000);        // LKR 500,000 per txn for tellers
if (!defined('HIGH_VALUE_THRESHOLD')) define('HIGH_VALUE_THRESHOLD', 100000000);   // LKR 1,000,000 flagged as high value

date_default_timezone_set('Asia/Colombo');
