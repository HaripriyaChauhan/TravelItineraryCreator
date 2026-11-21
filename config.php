<?php
// Shared helpers + PDO database connection + auto schema. (Owner: Tilak - Database & API)
date_default_timezone_set('Africa/Nairobi');
session_start();
header('Content-Type: application/json');

function body() { return json_decode(file_get_contents('php://input'), true) ?: []; }
function out($d, $code = 200) { http_response_code($code); echo json_encode($d); exit; }
function require_login() { if (empty($_SESSION['uid'])) out(['error' => 'Please log in'], 401); return $_SESSION['uid']; }
function new_id() { return bin2hex(random_bytes(6)); }
const FX_USD_KES = 129;       // demo exchange rate used to turn the fake API's USD-style prices into KES
const MPESA_MAX_TX = 500000;  // our guard for one M-Pesa payment (KES). Safaricom's own limits can be lower and may change - confirm with Safaricom; their error is shown to the user if they reject a payment
function pay_cfg() { static $c = null; if ($c === null) $c = require __DIR__ . '/payment_config.php'; return $c; }
function kes($usd) { return (int)(round($usd * FX_USD_KES / 50) * 50); }
// Signed price "quote": proves a price came from our search APIs, so the browser cannot invent a cheaper one.
function quote_sign($type, $ref, $unit) { return hash_hmac('sha256', $type . '|' . $ref . '|' . (int)$unit, pay_cfg()['quote_secret']); }
// 0712345678 / 712345678 / +254712345678 / 0112345678 -> 254712345678 (or null)
function norm_phone($p) { $p = preg_replace('/[\s\-()]/', '', (string)$p); return preg_match('/^(?:\+?254|0)?([71]\d{8})$/', $p, $m) ? '254' . $m[1] : null; }
// ---- Flight seats: 30 rows x 6 seats (A-C | aisle | D-F). Prices are fixed by row type and always recalculated on the server.
const SEAT_ROWS = 30; const SEAT_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
function seat_row_info($n) { if ($n <= 3) return ['extra', 4500]; if ($n <= 9) return ['preferred', 1500]; if ($n === 12 || $n === 13) return ['exit', 3500]; return ['standard', 0]; }
function seat_parse($code) { return preg_match('/^(\d{1,2})([A-F])$/', (string)$code, $m) && $m[1] >= 1 && $m[1] <= SEAT_ROWS ? [(int)$m[1], $m[2]] : null; }
function seat_price($code) { $p = seat_parse($code); return $p ? seat_row_info($p[0])[1] : 0; }
function seats_taken_base($ref) {   // seats already sold to other (imaginary) passengers, the same every time for the same flight
  mt_srand(crc32('seats|' . $ref)); $t = [];
  for ($r = 1; $r <= SEAT_ROWS; $r++) foreach (SEAT_LETTERS as $l) if (mt_rand(1, 100) <= 35) $t[] = $r . $l;
  return $t;
}
function seats_taken_by_others($db, $ref, $excludeId = '') {   // seats really paid for by other bookings on the same flight
  $s = $db->prepare("SELECT seats FROM bookings WHERE ref = ? AND status = 'Paid' AND id <> ? AND seats IS NOT NULL AND seats <> ''"); $s->execute([$ref, $excludeId]); $out = [];
  foreach ($s->fetchAll() as $r) foreach (explode(',', $r['seats']) as $c) $out[] = trim($c);
  return $out;
}
function seats_taken($db, $ref, $excludeId = '') { return array_values(array_unique(array_merge(seats_taken_base($ref), seats_taken_by_others($db, $ref, $excludeId)))); }
function db_driver() { pdo(); return $GLOBALS['DB_DRIVER']; }

function pdo() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = require __DIR__ . '/db_config.php';
  $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
  if (in_array($c['driver'], ['mysql', 'auto'])) {
    try {
      $p = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], $opt + [PDO::ATTR_TIMEOUT => 2]);
      $p->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4");
      $p->exec("USE `{$c['name']}`");
      $pdo = $p; $GLOBALS['DB_DRIVER'] = 'MySQL';
    } catch (Throwable $e) {
      if ($c['driver'] === 'mysql') out(['error' => 'MySQL connection failed: ' . $e->getMessage()], 500);
    }
  }
  if (!$pdo) {
    try {
      @mkdir(dirname($c['sqlite_file']));
      $pdo = new PDO('sqlite:' . $c['sqlite_file'], null, null, $opt);
      $pdo->exec('PRAGMA foreign_keys = ON');
      $GLOBALS['DB_DRIVER'] = 'SQLite';
    } catch (Throwable $e) { out(['error' => 'No database available: ' . $e->getMessage()], 500); }
  }
  create_schema($pdo, $GLOBALS['DB_DRIVER']);
  return $pdo;
}

// Same schema as database/schema.sql. Portable between MySQL and SQLite.
function create_schema($p, $driver) {
  $sfx = $driver === 'MySQL' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
  $fk = fn($col, $ref) => "FOREIGN KEY ($col) REFERENCES $ref(id) ON DELETE CASCADE";
  $p->exec("CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(16) PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, currency VARCHAR(5) NOT NULL DEFAULT 'KES', phone VARCHAR(20) NULL,
    reset_code VARCHAR(10) NULL, reset_exp INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)$sfx");
  $p->exec("CREATE TABLE IF NOT EXISTS trips (
    id VARCHAR(16) PRIMARY KEY, user_id VARCHAR(16) NOT NULL, name VARCHAR(160) NOT NULL, destination VARCHAR(200),
    start_date DATE NOT NULL, end_date DATE NOT NULL, travellers INT NOT NULL DEFAULT 1, budget DOUBLE NOT NULL DEFAULT 0,
    image VARCHAR(500) NULL, " . $fk('user_id', 'users') . ")$sfx");
  $p->exec("CREATE TABLE IF NOT EXISTS activities (
    id VARCHAR(16) PRIMARY KEY, trip_id VARCHAR(16) NOT NULL, type VARCHAR(20), title VARCHAR(200) NOT NULL,
    act_date DATE NOT NULL, act_time VARCHAR(5), duration INT DEFAULT 60, opens VARCHAR(5) NULL, closes VARCHAR(5) NULL, " . $fk('trip_id', 'trips') . ")$sfx");
  $p->exec("CREATE TABLE IF NOT EXISTS bookings (
    id VARCHAR(16) PRIMARY KEY, trip_id VARCHAR(16) NOT NULL, type VARCHAR(20), title VARCHAR(200) NOT NULL,
    book_date DATE NOT NULL, book_time VARCHAR(5), duration INT DEFAULT 60, detail VARCHAR(255) NULL, status VARCHAR(20) DEFAULT 'Confirmed',
    opens VARCHAR(5) NULL, closes VARCHAR(5) NULL, ref VARCHAR(190) NULL, unit_price DOUBLE NOT NULL DEFAULT 0, qty INT NOT NULL DEFAULT 1,
    price DOUBLE NOT NULL DEFAULT 0, quote VARCHAR(64) NULL, payment_id VARCHAR(16) NULL, seats VARCHAR(80) NULL, seat_fee DOUBLE NOT NULL DEFAULT 0, " . $fk('trip_id', 'trips') . ")$sfx");
  $p->exec("CREATE TABLE IF NOT EXISTS expenses (
    id VARCHAR(16) PRIMARY KEY, trip_id VARCHAR(16) NOT NULL, cat VARCHAR(30), title VARCHAR(200) NOT NULL,
    amount DOUBLE NOT NULL DEFAULT 0, exp_date DATE NOT NULL, " . $fk('trip_id', 'trips') . ")$sfx");
  // Payments are financial records: they are NOT deleted when a trip is deleted (trips with paid bookings cannot be deleted anyway).
  $p->exec("CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(16) PRIMARY KEY, user_id VARCHAR(16) NOT NULL, trip_id VARCHAR(16) NOT NULL, trip_name VARCHAR(160),
    method VARCHAR(12) NOT NULL, phone VARCHAR(24) NULL, amount DOUBLE NOT NULL, currency VARCHAR(5) NOT NULL DEFAULT 'KES',
    status VARCHAR(12) NOT NULL DEFAULT 'pending', provider_ref VARCHAR(100) NULL, merchant_ref VARCHAR(100) NULL,
    receipt VARCHAR(40) NULL, result_desc VARCHAR(255) NULL, items TEXT NOT NULL, created_at INT NOT NULL, paid_at INT NULL,
    " . $fk('user_id', 'users') . ")$sfx");
  // Upgrade databases created by older versions of the app
  foreach ([['users', 'phone', 'VARCHAR(20) NULL'], ['bookings', 'ref', 'VARCHAR(190) NULL'], ['bookings', 'unit_price', 'DOUBLE NOT NULL DEFAULT 0'],
            ['bookings', 'qty', 'INT NOT NULL DEFAULT 1'], ['bookings', 'price', 'DOUBLE NOT NULL DEFAULT 0'], ['bookings', 'quote', 'VARCHAR(64) NULL'],
            ['bookings', 'payment_id', 'VARCHAR(16) NULL'], ['bookings', 'seats', 'VARCHAR(80) NULL'], ['bookings', 'seat_fee', 'DOUBLE NOT NULL DEFAULT 0']] as [$t, $c, $d]) ensure_column($p, $driver, $t, $c, $d);
}
function ensure_column($p, $driver, $table, $col, $def) {
  if ($driver === 'MySQL') $has = (bool)$p->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
  else { $has = false; foreach ($p->query("PRAGMA table_info($table)") as $r) if ($r['name'] === $col) $has = true; }
  if (!$has) $p->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

function rows($db, $sql, $args) { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); }

function load_trips($db, $uid) {
  $out = [];
  foreach (rows($db, 'SELECT * FROM trips WHERE user_id = ? ORDER BY start_date', [$uid]) as $t) {
    $id = $t['id'];
    $out[] = [
      'id' => $id, 'name' => $t['name'], 'destination' => $t['destination'], 'start' => $t['start_date'], 'end' => $t['end_date'],
      'travellers' => (int)$t['travellers'], 'budget' => (float)$t['budget'], 'image' => $t['image'] ?? '',
      'activities' => array_map(fn($r) => ['id' => $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'date' => $r['act_date'], 'time' => $r['act_time'],
        'duration' => (int)$r['duration'], 'opens' => $r['opens'] ?? '', 'closes' => $r['closes'] ?? ''],
        rows($db, 'SELECT * FROM activities WHERE trip_id = ? ORDER BY act_date, act_time', [$id])),
      'bookings' => array_map(fn($r) => ['id' => $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'date' => $r['book_date'], 'time' => $r['book_time'],
        'duration' => (int)$r['duration'], 'detail' => $r['detail'] ?? '', 'status' => $r['status'] ?: 'Confirmed', 'opens' => $r['opens'] ?? '', 'closes' => $r['closes'] ?? '',
        'ref' => $r['ref'] ?? '', 'seats' => $r['seats'] ?? '', 'seat_fee' => (float)($r['seat_fee'] ?? 0), 'unit_price' => (float)$r['unit_price'], 'qty' => (int)$r['qty'], 'price' => (float)$r['price'], 'quote' => $r['quote'] ?? '', 'payment_id' => $r['payment_id'] ?? ''],
        rows($db, 'SELECT * FROM bookings WHERE trip_id = ? ORDER BY book_date, book_time', [$id])),
      'expenses' => array_map(fn($r) => ['id' => $r['id'], 'cat' => $r['cat'], 'title' => $r['title'], 'amount' => (float)$r['amount'], 'date' => $r['exp_date']],
        rows($db, 'SELECT * FROM expenses WHERE trip_id = ? ORDER BY exp_date', [$id])),
    ];
  }
  return $out;
}
