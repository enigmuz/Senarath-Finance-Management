<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/logger.php';
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';

ini_set('display_errors', '0');

set_exception_handler(static function (Throwable $t): void {
    sb_log('app.error', 'failure', ['error' => get_class($t), 'message' => substr($t->getMessage(), 0, 300), 'at' => basename($t->getFile()) . ':' . $t->getLine()], 'HIGH');
    if (!headers_sent()) http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font-family:Segoe UI,sans-serif;padding:40px">'
       . '<h2>Something went wrong</h2><p>The error has been logged. Reference: <code>' . e(SB_REQ_ID) . '</code></p><p><a href="dashboard.php">Back</a></p>';
});

sb_session_start();
