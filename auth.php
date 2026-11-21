<?php
// Registration, login, logout, profile, password reset - all stored in the database. (Owner: Krisha - Authentication)
require 'config.php';
$a = $_GET['action'] ?? '';
$b = body();
try {
  $db = pdo();
  $pub = fn($u) => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'currency' => 'KES', 'phone' => $u['phone'] ?? '', 'db' => db_driver()];
  $byEmail = function ($email) use ($db) { $s = $db->prepare('SELECT * FROM users WHERE email = ?'); $s->execute([strtolower(trim($email))]); return $s->fetch(); };

  if ($a === 'register') {
    $email = strtolower(trim($b['email'] ?? '')); $name = trim($b['name'] ?? ''); $pw = $b['password'] ?? '';
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) out(['error' => 'Enter a valid name and email'], 400);
    if (strlen($pw) < 6) out(['error' => 'Password must be at least 6 characters'], 400);
    if ($byEmail($email)) out(['error' => 'Email already registered'], 409);
    $id = new_id();
    $db->prepare('INSERT INTO users (id, name, email, password_hash) VALUES (?,?,?,?)')->execute([$id, $name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    session_regenerate_id(true); $_SESSION['uid'] = $id;
    out(['user' => $pub($byEmail($email))]);
  }
  if ($a === 'login') {
    $u = $byEmail($b['email'] ?? '');
    if ($u && password_verify($b['password'] ?? '', $u['password_hash'])) { session_regenerate_id(true); $_SESSION['uid'] = $u['id']; out(['user' => $pub($u)]); }
    out(['error' => 'Wrong email or password'], 401);
  }
  if ($a === 'logout') { session_destroy(); out(['ok' => true]); }
  if ($a === 'me') {
    $s = $db->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$_SESSION['uid'] ?? '']); $u = $s->fetch();
    out(['user' => $u ? $pub($u) : null]);
  }
  if ($a === 'update') {
    $uid = require_login();
    if (!empty($b['name'])) $db->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([trim($b['name']), $uid]);
    if (isset($b['phone'])) {
      $ph = trim($b['phone']) === '' ? null : norm_phone($b['phone']);
      if (trim($b['phone']) !== '' && !$ph) out(['error' => 'Enter a valid Kenyan phone number, e.g. 0712 345 678'], 400);
      $db->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$ph, $uid]);
    }
    if (!empty($b['password'])) $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($b['password'], PASSWORD_DEFAULT), $uid]);
    $s = $db->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$uid]); out(['user' => $pub($s->fetch())]);
  }
  if ($a === 'reset_request') {
    // DEMO ONLY: a real app would email this code. Here it is returned so the flow can be shown.
    $u = $byEmail($b['email'] ?? '');
    if (!$u) out(['error' => 'No account with that email'], 404);
    $code = (string)random_int(100000, 999999);
    $db->prepare('UPDATE users SET reset_code = ?, reset_exp = ? WHERE id = ?')->execute([$code, time() + 900, $u['id']]);
    out(['token' => $code]);
  }
  if ($a === 'reset_confirm') {
    if (strlen($b['password'] ?? '') < 6) out(['error' => 'Password must be at least 6 characters'], 400);
    $u = $byEmail($b['email'] ?? '');
    if ($u && $u['reset_code'] && $u['reset_code'] === trim($b['token'] ?? '') && $u['reset_exp'] > time()) {
      $db->prepare('UPDATE users SET password_hash = ?, reset_code = NULL, reset_exp = NULL WHERE id = ?')->execute([password_hash($b['password'], PASSWORD_DEFAULT), $u['id']]);
      out(['ok' => true]);
    }
    out(['error' => 'Invalid or expired code'], 400);
  }
  out(['error' => 'Unknown action'], 400);
} catch (PDOException $e) { out(['error' => 'Database error: ' . $e->getMessage()], 500); }
