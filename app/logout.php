<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    $mins = (int)round((time() - (int)($_SESSION['last_activity'] ?? time())) / 60);
    sb_log('auth.logout', 'success', ['idle_minutes' => $mins], 'INFO');
}
sb_end_session();
redirect('login.php?logged_out=1');
