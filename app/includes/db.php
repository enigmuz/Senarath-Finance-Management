<?php
/**
 * SQLite access + first-run install with synthetic lab data.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $installed = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$installed) {
        sb_db_install($pdo);
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function sb_db_install(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  full_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('admin','manager','teller','auditor')),
  branch TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','locked','disabled')),
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  last_login TEXT, last_ip TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cif TEXT NOT NULL UNIQUE,
  full_name TEXT NOT NULL,
  nic TEXT NOT NULL,
  phone TEXT, email TEXT,
  branch TEXT NOT NULL,
  created_by TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_no TEXT NOT NULL UNIQUE,
  customer_id INTEGER NOT NULL REFERENCES customers(id),
  type TEXT NOT NULL CHECK (type IN ('Savings','Current','Fixed Deposit')),
  balance INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','frozen','closed')),
  branch TEXT NOT NULL,
  opened_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL,
  account_id INTEGER NOT NULL REFERENCES accounts(id),
  type TEXT NOT NULL CHECK (type IN ('DEPOSIT','WITHDRAWAL','TRANSFER_IN','TRANSFER_OUT')),
  amount INTEGER NOT NULL,
  balance_after INTEGER NOT NULL,
  counterparty TEXT,
  narration TEXT,
  performed_by TEXT NOT NULL,
  flagged INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX ix_txn_account ON transactions(account_id);
CREATE INDEX ix_txn_created ON transactions(created_at);
SQL);
    sb_db_seed($pdo);
}

function sb_db_seed(PDO $pdo): void
{
    mt_srand(20261110);
    $pdo->beginTransaction();

    $users = [
        ['admin',    'System Administrator',   'Admin@123',   'admin',   'Head Office'],
        ['manager1', 'Kumari Senanayake',      'Manager@123', 'manager', 'Colombo Main'],
        ['teller1',  'Dinesh Rathnayake',      'Teller@123',  'teller',  'Colombo Main'],
        ['teller2',  'Shehani Wijesinghe',     'Teller@123',  'teller',  'Galle'],
        ['auditor1', 'Priyanka Mendis',        'Auditor@123', 'auditor', 'Head Office'],
    ];
    $st = $pdo->prepare('INSERT INTO users (username, full_name, password_hash, role, branch) VALUES (?,?,?,?,?)');
    foreach ($users as $u) {
        $st->execute([$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT), $u[3], $u[4]]);
    }

    $branches = ['Colombo Main', 'Kandy', 'Galle', 'Matara', 'Negombo'];
    $names = ['Nimal Perera', 'Kasun Fernando', 'Dilani Jayawardena', 'Tharindu Silva', 'Sanduni Wickramasinghe',
              'Ruwan Bandara', 'Ishara Gunasekara', 'Chamara Herath', 'Malsha de Mel', 'Pradeep Kumara',
              'Hiruni Abeysekera', 'Fathima Rizna', 'Suresh Navaratnam', 'Anjali Ratnayake'];
    $cst = $pdo->prepare('INSERT INTO customers (cif, full_name, nic, phone, email, branch, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $ast = $pdo->prepare('INSERT INTO accounts (account_no, customer_id, type, balance, branch, opened_at) VALUES (?,?,?,?,?,?)');
    $accounts = [];
    $acctSeq = 1;
    foreach ($names as $i => $name) {
        $branch = $branches[$i % count($branches)];
        $email = strtolower(str_replace(' ', '.', $name)) . '@example.lk';
        $nic = (string)mt_rand(1975, 2002) . str_pad((string)mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
        $phone = '07' . mt_rand(0, 8) . str_pad((string)mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
        $created = date('Y-m-d H:i:s', strtotime('-' . mt_rand(60, 900) . ' days'));
        $cst->execute(['CIF' . (100001 + $i), $name, $nic, $phone, $email, $branch, 'system', $created]);
        $cid = (int)$pdo->lastInsertId();
        $types = ['Savings'];
        if ($i % 3 === 0) $types[] = 'Current';
        if ($i % 5 === 0) $types[] = 'Fixed Deposit';
        foreach ($types as $t) {
            $no = '1001' . str_pad((string)$acctSeq++, 8, '0', STR_PAD_LEFT);
            $ast->execute([$no, $cid, $t, 0, $branch, $created]);
            $accounts[] = ['id' => (int)$pdo->lastInsertId(), 'no' => $no, 'bal' => 0];
        }
    }

    // ~30 days of synthetic activity
    $tst = $pdo->prepare('INSERT INTO transactions (ref, account_id, type, amount, balance_after, counterparty, narration, performed_by, flagged, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $tellers = ['teller1', 'teller2', 'manager1'];
    $start = strtotime('-30 days');
    foreach ($accounts as $k => $a) {
        $amt = mt_rand(50, 1500) * 100000; // LKR 50k - 1.5M opening
        $accounts[$k]['bal'] += $amt;
        $tst->execute([sb_txn_ref($start - 86400), $a['id'], 'DEPOSIT', $amt, $accounts[$k]['bal'], null, 'Opening balance', 'system', 0, date('Y-m-d H:i:s', $start - 86400)]);
    }
    $events = [];
    for ($n = 0; $n < 160; $n++) {
        $events[] = $start + mt_rand(0, 30 * 86400 - 3600);
    }
    sort($events);
    $narr = ['Cash deposit', 'Salary credit', 'Cheque deposit', 'ATM withdrawal', 'Utility payment', 'Cash withdrawal', 'Loan installment'];
    foreach ($events as $ts) {
        $k = mt_rand(0, count($accounts) - 1);
        $kind = mt_rand(1, 10);
        $amt = mt_rand(5, 2500) * 1000; // LKR 50 - 25,000 typical
        if (mt_rand(1, 25) === 1) $amt = mt_rand(100, 180) * 1000000; // occasional high value
        $by = $tellers[mt_rand(0, 2)];
        $when = date('Y-m-d H:i:s', $ts);
        if ($kind <= 5) {
            $accounts[$k]['bal'] += $amt;
            $tst->execute([sb_txn_ref($ts), $accounts[$k]['id'], 'DEPOSIT', $amt, $accounts[$k]['bal'], null, $narr[mt_rand(0, 2)], $by, $amt >= HIGH_VALUE_THRESHOLD ? 1 : 0, $when]);
        } elseif ($kind <= 8) {
            if ($accounts[$k]['bal'] < $amt) continue;
            $accounts[$k]['bal'] -= $amt;
            $tst->execute([sb_txn_ref($ts), $accounts[$k]['id'], 'WITHDRAWAL', $amt, $accounts[$k]['bal'], null, $narr[mt_rand(3, 6)], $by, $amt >= HIGH_VALUE_THRESHOLD ? 1 : 0, $when]);
        } else {
            $j = mt_rand(0, count($accounts) - 1);
            if ($j === $k || $accounts[$k]['bal'] < $amt) continue;
            $ref = sb_txn_ref($ts);
            $flag = $amt >= HIGH_VALUE_THRESHOLD ? 1 : 0;
            $accounts[$k]['bal'] -= $amt;
            $accounts[$j]['bal'] += $amt;
            $tst->execute([$ref, $accounts[$k]['id'], 'TRANSFER_OUT', $amt, $accounts[$k]['bal'], $accounts[$j]['no'], 'Fund transfer', $by, $flag, $when]);
            $tst->execute([$ref, $accounts[$j]['id'], 'TRANSFER_IN', $amt, $accounts[$j]['bal'], $accounts[$k]['no'], 'Fund transfer', $by, $flag, $when]);
        }
    }
    $up = $pdo->prepare('UPDATE accounts SET balance = ? WHERE id = ?');
    foreach ($accounts as $a) $up->execute([$a['bal'], $a['id']]);
    $pdo->commit();
}

function sb_txn_ref(?int $ts = null): string
{
    return 'TXN' . date('ymd', $ts ?? time()) . strtoupper(bin2hex(random_bytes(3)));
}
