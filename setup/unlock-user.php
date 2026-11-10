<?php
// Usage: C:\PHP\php.exe C:\SenarathFMS\setup\unlock-user.php <username> [new-password]
$db = __DIR__ . '/../app/data/sbfms.sqlite';
$user = $argv[1] ?? '';
if ($user === '' || !is_file($db)) { fwrite(STDERR, "usage: php unlock-user.php <username> [new-password]\n(database: $db)\n"); exit(1); }
$pdo = new PDO('sqlite:' . $db);
$sql = "UPDATE users SET status = 'active', failed_attempts = 0" . (isset($argv[2]) ? ', password_hash = :h' : '') . ' WHERE username = :u';
$st = $pdo->prepare($sql);
$args = [':u' => $user];
if (isset($argv[2])) $args[':h'] = password_hash($argv[2], PASSWORD_DEFAULT);
$st->execute($args);
echo $st->rowCount() ? "User '$user' is active" . (isset($argv[2]) ? ' with the new password' : '') . ".\n" : "User '$user' not found.\n";
