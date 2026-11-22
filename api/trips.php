<?php
// Trip CRUD. A trip holds its activities, bookings and expenses.
// MONEY RULES (server-side, the browser is never trusted):
//   * a booking is only payable ("Reserved") if it carries a valid signed quote from our search APIs; price = unit_price x qty, recomputed here
//   * bookings without a valid quote are "Confirmed" external records (booked elsewhere) with price 0
//   * "Paid" bookings and payment-generated expenses (id starts "pay_") can never be changed or deleted from here
// (Owner: Haripriya / Tilak)
require 'config.php';
$uid = require_login();
$m = $_SERVER['REQUEST_METHOD'];


try {
  $db = pdo();
  if ($m === 'GET') out(['trips' => load_trips($db, $uid)]);

  if ($m === 'POST') {
    $t = body();
    if (empty($t['name']) || empty($t['start']) || empty($t['end'])) out(['error' => 'Name and dates required'], 400);
    if ($t['end'] < $t['start']) out(['error' => 'End date is before start date'], 400);
    $id = $t['id'] ?? '';
    $paidIds = []; 
    if ($id) {
      if (!rows($db, 'SELECT id FROM trips WHERE id = ? AND user_id = ?', [$id, $uid])) out(['error' => 'Trip not found'], 404);
      $paidIds = array_column(rows($db, "SELECT id FROM bookings WHERE trip_id = ? AND status = 'Paid'", [$id]), 'id');
    }
    // Validate + price every booking BEFORE touching the database (so a bad seat never leaves half a trip saved).
    $rowsToSave = [];
    foreach ($t['bookings'] ?? [] as $b) {
      if (in_array($b['id'] ?? '', $paidIds, true)) continue;           // paid bookings are immutable
      $type = $b['type'] ?? 'activity'; $ref = (string)($b['ref'] ?? ''); $unit = (float)($b['unit_price'] ?? 0); $q = (string)($b['quote'] ?? '');
      $seats = ''; $fee = 0;
      if ($q !== '' && $unit > 0 && hash_equals(quote_sign($type, $ref, $unit), $q)) {
        $qty = max(1, min(365, (int)($b['qty'] ?? 1))); $status = 'Reserved';
        if ($type === 'flight' && trim((string)($b['seats'] ?? '')) !== '') {
          $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$b['seats'])))));
          if (count($codes) > $qty) out(['error' => 'You chose more seats than travellers on ' . ($b['title'] ?? 'this flight')], 400);
          $taken = seats_taken($db, $ref, (string)($b['id'] ?? ''));
          foreach ($codes as $c) {
            if (!seat_parse($c)) out(['error' => "Seat $c does not exist"], 400);
            if (in_array($c, $taken, true)) out(['error' => "Seat $c is no longer available. Please choose another seat."], 409);
            $fee += seat_price($c);
          }
          $seats = implode(',', $codes);
        }
        $price = $unit * $qty + $fee;
      } else { $unit = 0; $qty = 1; $price = 0; $status = 'Confirmed'; $q = ''; $ref = ''; }
      $rowsToSave[] = [$b['id'] ?? new_id(), $type, $b['title'], $b['date'], $b['time'] ?? '12:00', (int)($b['duration'] ?? 60), $b['detail'] ?? null, $status,
        ($b['opens'] ?? '') ?: null, ($b['closes'] ?? '') ?: null, $ref ?: null, $unit, $qty, $price, $q ?: null, $seats ?: null, $fee];
    }
    $db->beginTransaction();
    $vals = [$t['name'], $t['destination'] ?? '', $t['start'], $t['end'], (int)($t['travellers'] ?? 1), (float)($t['budget'] ?? 0), $t['image'] ?? null];
    if ($id) {
      $db->prepare('UPDATE trips SET name=?, destination=?, start_date=?, end_date=?, travellers=?, budget=?, image=? WHERE id=?')->execute([...$vals, $id]);
      $db->prepare('DELETE FROM activities WHERE trip_id = ?')->execute([$id]);
      $db->prepare("DELETE FROM bookings WHERE trip_id = ? AND COALESCE(status,'') <> 'Paid'")->execute([$id]);
      $db->prepare("DELETE FROM expenses WHERE trip_id = ? AND SUBSTR(id,1,4) <> 'pay_'")->execute([$id]);
    } else {
      $id = new_id();
      $db->prepare('INSERT INTO trips (name, destination, start_date, end_date, travellers, budget, image, id, user_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute([...$vals, $id, $uid]);
    }
    foreach ($t['activities'] ?? [] as $a)
      $db->prepare('INSERT INTO activities (id, trip_id, type, title, act_date, act_time, duration, opens, closes) VALUES (?,?,?,?,?,?,?,?,?)')
         ->execute([$a['id'] ?? new_id(), $id, $a['type'] ?? 'activity', $a['title'], $a['date'], $a['time'] ?? '09:00', (int)($a['duration'] ?? 60), ($a['opens'] ?? '') ?: null, ($a['closes'] ?? '') ?: null]);
    foreach ($rowsToSave as $r)
      $db->prepare('INSERT INTO bookings (id, trip_id, type, title, book_date, book_time, duration, detail, status, opens, closes, ref, unit_price, qty, price, quote, seats, seat_fee, payment_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)')
         ->execute([$r[0], $id, ...array_slice($r, 1)]);
    foreach ($t['expenses'] ?? [] as $e) {
      if (strncmp($e['id'] ?? '', 'pay_', 4) === 0) continue;            // payment records are server-owned
      $db->prepare('INSERT INTO expenses (id, trip_id, cat, title, amount, exp_date) VALUES (?,?,?,?,?,?)')
         ->execute([$e['id'] ?? new_id(), $id, $e['cat'] ?? 'Activities', $e['title'], (float)$e['amount'], $e['date']]);
    }
    $db->commit();
    foreach (load_trips($db, $uid) as $x) if ($x['id'] === $id) out(['trip' => $x]);
  }

  if ($m === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (rows($db, 'SELECT id FROM trips WHERE id = ? AND user_id = ?', [$id, $uid])) {
      if (rows($db, "SELECT id FROM bookings WHERE trip_id = ? AND status = 'Paid'", [$id]))
        out(['error' => 'This trip has paid bookings, so it cannot be deleted. Contact support to cancel and refund first.'], 409);
      foreach (['activities', 'bookings', 'expenses'] as $tb) $db->prepare("DELETE FROM $tb WHERE trip_id = ?")->execute([$id]);
      $db->prepare('DELETE FROM trips WHERE id = ?')->execute([$id]);
    }
    out(['ok' => true]);
  }
  out(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
  if (isset($db) && $db->inTransaction()) $db->rollBack();
  out(['error' => 'Database error: ' . $e->getMessage()], 500);
}
