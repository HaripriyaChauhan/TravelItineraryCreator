<?php
// SAMPLE TRIPS (created on the server with real signed prices, seat choices and payment records). (Owner: Kashish)
//   planning : "Greece Adventure": items reserved but NOT paid yet, seats chosen, one deliberate schedule conflict
//   complete : "Bali Escape": everything booked and PAID (flights with seats, hotel, activities, meals), receipts in Payments, no conflicts
require 'catalog.php';
$uid = require_login(); $db = pdo(); $kind = body()['kind'] ?? '';
if (!in_array($kind, ['planning', 'complete'])) out(['error' => 'Unknown sample trip'], 400);
$name = $kind === 'planning' ? 'Greece Adventure' : 'Bali Escape';
if (rows($db, 'SELECT id FROM trips WHERE user_id = ? AND name = ?', [$uid, $name])) out(['error' => "'{$name}' is already in your trips"], 409);

function ins($db, $table, $row) { $c = array_keys($row); $db->prepare("INSERT INTO $table (" . implode(',', $c) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($row)); }
function bk($tid, $type, $title, $date, $time, $dur, $detail, $status, $x = []) {
  return array_merge(['id' => new_id(), 'trip_id' => $tid, 'type' => $type, 'title' => $title, 'book_date' => $date, 'book_time' => $time, 'duration' => $dur, 'detail' => $detail, 'status' => $status,
    'opens' => null, 'closes' => null, 'ref' => null, 'unit_price' => 0, 'qty' => 1, 'price' => 0, 'quote' => null, 'payment_id' => null, 'seats' => null, 'seat_fee' => 0], $x);
}
function pick_seats($db, $ref, $n, $from, $to) {     // n adjacent free seats in one block, e.g. 14A + 14B
  $taken = seats_taken($db, $ref);
  for ($r = $from; $r <= $to; $r++) foreach ([['A', 'B', 'C'], ['D', 'E', 'F']] as $blk) for ($i = 0; $i + $n <= 3; $i++) {
    $codes = []; for ($j = 0; $j < $n; $j++) $codes[] = $r . $blk[$i + $j]; if (!array_intersect($codes, $taken)) return $codes;
  }
  return [];
}
function flight_bk($db, $tid, $f, $trav, $seatRows, $status) {
  $seats = pick_seats($db, $f['ref'], $trav, $seatRows[0], $seatRows[1]); $fee = array_sum(array_map('seat_price', $seats));
  return bk($tid, 'flight', "Flight {$f['from']}→{$f['to']} ({$f['number']})", $f['date'], $f['depart'], $f['duration'],
    "{$f['airline']} · $trav traveller" . ($trav > 1 ? 's' : '') . ' × KES ' . number_format($f['unit_price']) . ($seats ? ' · Seats ' . implode(', ', $seats) . ($fee ? ' (+KES ' . number_format($fee) . ')' : '') : ''),
    $status, ['ref' => $f['ref'], 'unit_price' => $f['unit_price'], 'qty' => $trav, 'quote' => $f['quote'], 'price' => $f['unit_price'] * $trav + $fee, 'seats' => $seats ? implode(',', $seats) : null, 'seat_fee' => $fee]);
}
function venue_bk($tid, $v, $qty, $date, $time, $status) {
  $per = $v['type'] === 'hotel' ? "$qty night" . ($qty > 1 ? 's' : '') . ' × KES ' . number_format($v['unit_price']) : "$qty × KES " . number_format($v['unit_price']) . ' per person';
  return bk($tid, $v['type'], $v['name'], $date, $time, $v['duration'], $per, $status, ['opens' => $v['opens'] ?? null, 'closes' => $v['closes'] ?? null, 'ref' => $v['ref'], 'unit_price' => $v['unit_price'], 'qty' => $qty, 'quote' => $v['quote'], 'price' => $v['unit_price'] * $qty]);
}
function tmin($t) { [$h, $m] = array_map('intval', explode(':', $t)); return $h * 60 + $m; }
function hhmm($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
function fit($pref, $v) { $lo = tmin($v['opens']); $hi = tmin($v['closes']) - $v['duration']; return hhmm(max($lo, min($pref, max($lo, $hi)))); }
function best($list) { usort($list, fn($a, $b) => $b['rating'] <=> $a['rating']); return $list; }
function activity($tid, $title, $date, $time, $dur, $type = 'activity') { return ['id' => new_id(), 'trip_id' => $tid, 'type' => $type, 'title' => $title, 'act_date' => $date, 'act_time' => $time, 'duration' => $dur, 'opens' => null, 'closes' => null]; }
$cat = ['flight' => 'Flights', 'hotel' => 'Accommodation', 'activity' => 'Activities', 'restaurant' => 'Food'];

$tid = new_id(); $db->beginTransaction();
if ($kind === 'planning') {
  $d = fn($n) => date('Y-m-d', strtotime('+' . (14 + $n) . ' days')); $trav = 2;
  $fl = catalog_flights('NBO', 'ATH', $d(0))[0]; $ho = catalog_venues('hotel', 'Athens')[0]; $ac = catalog_venues('activity', 'Athens')[0]; $re = catalog_venues('restaurant', 'Athens')[0];
  ins($db, 'trips', ['id' => $tid, 'user_id' => $uid, 'name' => $name, 'destination' => 'Athens, Santorini', 'start_date' => $d(0), 'end_date' => $d(5), 'travellers' => $trav, 'budget' => 400000, 'image' => null]);
  foreach ([activity($tid, 'Evening: Explore Plaka', $d(1), '18:00', 90), activity($tid, 'Acropolis visit', $d(1), '09:00', 120), activity($tid, 'Dinner in Oia', $d(2), '17:30', 90, 'restaurant')] as $a) ins($db, 'activities', $a);
  foreach ([flight_bk($db, $tid, $fl, $trav, [1, 3], 'Reserved'), venue_bk($tid, $ho, 5, $d(0), '15:00', 'Reserved'), venue_bk($tid, $ac, $trav, $d(2), '17:00', 'Reserved'), venue_bk($tid, $re, $trav, $d(1), '20:00', 'Reserved'),
    bk($tid, 'activity', 'Airport transfer to departure (arranged by hotel)', $d(5), '08:00', 45, 'Booked elsewhere', 'Confirmed')] as $b) ins($db, 'bookings', $b);
  ins($db, 'expenses', ['id' => new_id(), 'trip_id' => $tid, 'cat' => 'Activities', 'title' => 'Travel insurance', 'amount' => 6500, 'exp_date' => $d(0)]);
} else {
  $trav = 2; $found = null;
  for ($off = 30; $off < 70 && !$found; $off++) {         // a start date whose outbound flight lands the same day, so nothing overlaps
    $start = date('Y-m-d', strtotime("+$off days")); $end = date('Y-m-d', strtotime("+" . ($off + 6) . " days"));
    foreach (catalog_flights('NBO', 'DPS', $start) as $f) if (tmin($f['depart']) + $f['duration'] <= 1260) { $found = [$start, $end, $f]; break; }
  }
  [$start, $end, $out] = $found; $d = fn($n) => date('Y-m-d', strtotime($start) + $n * 86400);
  $rets = catalog_flights('DPS', 'NBO', $end); $ret = $rets[0]; foreach ($rets as $f) if (tmin($f['depart']) >= 480) { $ret = $f; break; }
  $ho = best(catalog_venues('hotel', 'Bali'))[0]; $acs = best(catalog_venues('activity', 'Bali')); $res = best(catalog_venues('restaurant', 'Bali'));
  $checkin = hhmm(max(900, tmin($out['depart']) + $out['duration'] + 60));
  $paidB = ['out' => flight_bk($db, $tid, $out, $trav, [4, 9], 'Paid'), 'ret' => flight_bk($db, $tid, $ret, $trav, [4, 9], 'Paid'), 'hotel' => venue_bk($tid, $ho, 6, $d(0), $checkin, 'Paid'),
    'a1' => venue_bk($tid, $acs[0], $trav, $d(1), fit(600, $acs[0]), 'Paid'), 'a2' => venue_bk($tid, $acs[1], $trav, $d(3), fit(600, $acs[1]), 'Paid'),
    'r1' => venue_bk($tid, $res[0], $trav, $d(2), fit(750, $res[0]), 'Paid'), 'r2' => venue_bk($tid, $res[1], $trav, $d(4), fit(750, $res[1]), 'Paid')];
  $paidTotal = array_sum(array_column($paidB, 'price')); $manual = 8500 + 5000;
  ins($db, 'trips', ['id' => $tid, 'user_id' => $uid, 'name' => $name, 'destination' => 'Bali, Indonesia', 'start_date' => $start, 'end_date' => $end, 'travellers' => $trav, 'budget' => (int)(ceil(($paidTotal + $manual) * 1.2 / 10000) * 10000), 'image' => null]);
  foreach ([activity($tid, 'Rice terraces & temple drive', $d(2), '09:00', 180), activity($tid, 'Beach day at Seminyak', $d(5), '10:00', 240), activity($tid, 'Spa afternoon', $d(5), '15:00', 120)] as $a) ins($db, 'activities', $a);
  ins($db, 'bookings', bk($tid, 'activity', 'Hotel shuttle to the airport', $d(6), hhmm(max(0, tmin($ret['depart']) - 240)), 60, 'Booked with the hotel', 'Confirmed'));
  ins($db, 'expenses', ['id' => new_id(), 'trip_id' => $tid, 'cat' => 'Activities', 'title' => 'Travel insurance', 'amount' => 8500, 'exp_date' => $d(-8)]);
  ins($db, 'expenses', ['id' => new_id(), 'trip_id' => $tid, 'cat' => 'Activities', 'title' => 'Visa on arrival (Indonesia)', 'amount' => 5000, 'exp_date' => $d(-8)]);
  // two real-looking payments: flights by M-Pesa, the rest by card
  $u = $db->prepare('SELECT phone FROM users WHERE id = ?'); $u->execute([$uid]); $phone = $u->fetchColumn() ?: '254712345678';
  foreach ([['mpesa', $phone, ['out', 'ret'], time() - 4 * 86400, 'S' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9))],
            ['card', '**** 4242', ['hotel', 'a1', 'a2', 'r1', 'r2'], time() - 3 * 86400, 'CD' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 8))]] as [$method, $who, $keys, $when, $receipt]) {
    $pid = new_id(); $items = []; $cats = [];
    foreach ($keys as $k) { $b = &$paidB[$k]; $b['payment_id'] = $pid; $cats[$cat[$b['type']]] = ($cats[$cat[$b['type']]] ?? 0) + $b['price'];
      $items[] = ['id' => $b['id'], 'type' => $b['type'], 'title' => $b['title'], 'amount' => (float)$b['price'], 'detail' => $b['seats'] ? 'Seats ' . str_replace(',', ', ', $b['seats']) : '']; unset($b); }
    ins($db, 'payments', ['id' => $pid, 'user_id' => $uid, 'trip_id' => $tid, 'trip_name' => $name, 'method' => $method, 'phone' => $who, 'amount' => array_sum(array_column($items, 'amount')), 'currency' => 'KES', 'status' => 'success',
      'provider_ref' => 'seed_' . $pid, 'merchant_ref' => 'seed', 'receipt' => $receipt, 'result_desc' => 'Payment received', 'items' => json_encode($items), 'created_at' => $when, 'paid_at' => $when + 30]);
    $i = 0; foreach ($cats as $cn => $sum) ins($db, 'expenses', ['id' => 'pay_' . substr($pid, 0, 8) . (++$i), 'trip_id' => $tid, 'cat' => $cn, 'title' => 'Paid via ' . ($method === 'mpesa' ? 'M-Pesa' : 'Card') . " ($cn)", 'amount' => $sum, 'exp_date' => date('Y-m-d', $when)]);
  }
  foreach ($paidB as $b) ins($db, 'bookings', $b);
}
$db->commit();
out(['trip_id' => $tid, 'trips' => load_trips($db, $uid)]);
