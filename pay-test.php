<?php
// Backend test for payments, pricing quotes and security rules. (Owner: Yogi)
// Run:  TIC_MOCK_DELAY=1 php -S localhost:8766 &   then   php tests/pay-test.php 8766      (tests/api-test.sh does this for you)
$port = $argv[1] ?? 8766; $base = "http://localhost:$port/api"; $jar = sys_get_temp_dir() . '/tic-pay-' . getmypid() . '.txt';
$P = 0; $F = 0;
function call($method, $path, $body = null, $jarfile = null) {
  global $base, $jar; $ch = curl_init("$base/$path"); $jf = $jarfile ?: $jar;
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jf, CURLOPT_COOKIEFILE => $jf, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, json_decode($r, true)];
}
function ok($name, $cond, $info = '') { global $P, $F; if ($cond) { $P++; echo "PASS $name\n"; } else { $F++; echo "FAIL $name $info\n"; } }

$email = 'pay' . time() . rand(100, 999) . '@test.com';
[$c, $r] = call('POST', 'auth.php?action=register', ['name' => 'Pay Tester', 'email' => $email, 'password' => 'secret1']); ok('register', $c === 200);
[$c, $r] = call('GET', 'payments.php?action=config'); ok('config: demo mode, all methods', $r['mode'] === 'mock' && $r['methods']['airtel'] && $r['mpesa_max'] === 500000);

// ---- real signed quotes from the fake APIs
[$c, $fl] = call('GET', 'flights.php?from=NBO&to=ATH&date=2026-12-01'); $f = $fl['flights'][0];
ok('flight result has signed quote + KES price', !empty($f['quote']) && $f['unit_price'] > 0 && $f['currency'] === 'KES');
[$c, $ho] = call('GET', 'search.php?type=hotel&city=Athens'); $h = $ho['results'][0];
ok('hotel result has quote', !empty($h['quote']) && $h['price'] % 50 === 0);

$trip = ['name' => 'Greece', 'destination' => 'Athens', 'start' => '2026-12-01', 'end' => '2026-12-06', 'travellers' => 2, 'budget' => 300000,
  'activities' => [], 'expenses' => [], 'bookings' => [
    ['id' => 'bk1', 'type' => 'flight', 'title' => 'Flight', 'date' => '2026-12-01', 'time' => '10:00', 'ref' => $f['ref'], 'unit_price' => $f['unit_price'], 'qty' => 2, 'quote' => $f['quote'], 'price' => 1, 'status' => 'Paid'],
    ['id' => 'bk2', 'type' => 'hotel', 'title' => 'Hotel', 'date' => '2026-12-01', 'time' => '15:00', 'ref' => $h['ref'], 'unit_price' => $h['unit_price'], 'qty' => 5, 'quote' => $h['quote']],
    ['id' => 'bk3', 'type' => 'activity', 'title' => 'Cheap trick', 'date' => '2026-12-02', 'time' => '10:00', 'ref' => 'x', 'unit_price' => 1, 'qty' => 1, 'quote' => 'forged'],
    ['id' => 'bk4', 'type' => 'hotel', 'title' => 'Wrong type reuse', 'date' => '2026-12-02', 'time' => '10:00', 'ref' => $f['ref'], 'unit_price' => $f['unit_price'], 'qty' => 1, 'quote' => $f['quote']],
    ['id' => 'bk5', 'type' => 'hotel', 'title' => 'Huge stay', 'date' => '2026-12-02', 'time' => '10:00', 'ref' => $h['ref'], 'unit_price' => $h['unit_price'], 'qty' => 365, 'quote' => $h['quote']],
  ]];
[$c, $r] = call('POST', 'trips.php', $trip); $tid = $r['trip']['id']; $bk = array_column($r['trip']['bookings'], null, 'id');
ok('flight: server prices it (unit x qty), client price/status ignored', $bk['bk1']['status'] === 'Reserved' && $bk['bk1']['price'] == $f['unit_price'] * 2, json_encode($bk['bk1']));
ok('hotel: Reserved, 5 nights', $bk['bk2']['status'] === 'Reserved' && $bk['bk2']['price'] == $h['unit_price'] * 5);
ok('forged quote -> external Confirmed booking, price 0', $bk['bk3']['status'] === 'Confirmed' && $bk['bk3']['price'] == 0);
ok('quote cannot be reused for another type', $bk['bk4']['status'] === 'Confirmed' && $bk['bk4']['price'] == 0);

// ---- validation
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '12345', 'booking_ids' => ['bk1']]); ok('bad phone rejected', $c === 400);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0712345678', 'booking_ids' => ['bk5']]); ok('M-Pesa limit enforced (> KES 500,000)', $c === 400 && str_contains($r['error'], '500,000'), json_encode($r));
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0712345678', 'booking_ids' => ['bk3']]); ok('cannot pay for non-payable booking', $c === 400);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => 'nope', 'method' => 'mpesa', 'phone' => '0712345678']); ok('unknown trip rejected', $c === 404);

// ---- successful M-Pesa payment of the flight only
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '+254 712 345 678', 'booking_ids' => ['bk1']]);
$pay = $r['payment'] ?? []; ok('STK push created (pending, amount from DB)', $c === 200 && $pay['status'] === 'pending' && $pay['amount'] == $f['unit_price'] * 2 && str_starts_with($pay['phone'], '254712'), json_encode($r));
[$c2, $r2] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0712345678', 'booking_ids' => ['bk2']]); ok('second payment while one is pending -> 409', $c2 === 409);
sleep(2);
[$c, $r] = call('GET', 'payments.php?action=status&id=' . $pay['id']); $done = $r['payment'];
ok('payment succeeds with M-Pesa receipt code', $done['status'] === 'success' && preg_match('/^S[A-Z0-9]{9}$/', $done['receipt']), json_encode($done));
[$c, $r] = call('GET', 'trips.php'); $t = $r['trips'][0]; $bk = array_column($t['bookings'], null, 'id');
ok('booking marked Paid + linked to payment', $bk['bk1']['status'] === 'Paid' && $bk['bk1']['payment_id'] === $pay['id'] && $bk['bk2']['status'] === 'Reserved');
$payExp = array_filter($t['expenses'], fn($e) => str_starts_with($e['id'], 'pay_'));
ok('expense logged automatically for the budget', count($payExp) === 1 && reset($payExp)['amount'] == $f['unit_price'] * 2 && reset($payExp)['cat'] === 'Flights');
[$c, $r] = call('GET', 'payments.php?action=status&id=' . $pay['id']); ok('status call is idempotent (no double expense)', count(array_filter(call('GET', 'trips.php')[1]['trips'][0]['expenses'], fn($e) => str_starts_with($e['id'], 'pay_'))) === 1);
[$c, $r] = call('GET', 'auth.php?action=me'); ok('phone saved to profile after first payment', $r['user']['phone'] === '254712345678');

// ---- tampering after payment
$t['bookings'] = array_values(array_filter($t['bookings'], fn($b) => $b['id'] !== 'bk1'));      // try to delete the paid booking
foreach ($t['bookings'] as &$b) if ($b['id'] === 'bk2') { $b['unit_price'] = 1; }                // try to lower an unpaid price
unset($b); $t['expenses'] = array_values(array_filter($t['expenses'], fn($e) => !str_starts_with($e['id'], 'pay_')));
[$c, $r] = call('POST', 'trips.php', $t); $bk = array_column($r['trip']['bookings'], null, 'id');
ok('paid booking survives a save that deletes it', isset($bk['bk1']) && $bk['bk1']['status'] === 'Paid');
ok('lowered unit_price breaks the quote -> not payable', $bk['bk2']['status'] === 'Confirmed' && $bk['bk2']['price'] == 0);
ok('payment expense cannot be deleted from the browser', count(array_filter($r['trip']['expenses'], fn($e) => str_starts_with($e['id'], 'pay_'))) === 1);
[$c, $r] = call('DELETE', "trips.php?id=$tid"); ok('trip with paid bookings cannot be deleted', $c === 409);

// ---- failure paths (demo numbers)
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'airtel', 'phone' => '0700000000', 'booking_ids' => ['bk3']]);   // bk3 is an external booking -> nothing payable
ok('no payable items -> 400', $c === 400);
$t = call('GET', 'trips.php')[1]['trips'][0]; $t['bookings'][] = ['id' => 'bk6', 'type' => 'activity', 'title' => 'Tour', 'date' => '2026-12-03', 'time' => '09:00', 'ref' => $ho['results'][0]['ref'], 'unit_price' => 1, 'qty' => 1];
[$ac, $acts] = call('GET', 'search.php?type=activity&city=Athens'); $ac1 = $acts['results'][0];
$t['bookings'][count($t['bookings']) - 1] = ['id' => 'bk6', 'type' => 'activity', 'title' => 'Tour', 'date' => '2026-12-03', 'time' => '09:00', 'ref' => $ac1['ref'], 'unit_price' => $ac1['unit_price'], 'qty' => 2, 'quote' => $ac1['quote']];
call('POST', 'trips.php', $t);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0700000000', 'booking_ids' => ['bk6']]); $p2 = $r['payment']; sleep(2);
[$c, $r] = call('GET', 'payments.php?action=status&id=' . $p2['id']); ok('customer cancels on phone -> cancelled, booking stays Reserved', $r['payment']['status'] === 'cancelled' && call('GET', 'trips.php')[1]['trips'][0]['bookings'][array_search('bk6', array_column(call('GET', 'trips.php')[1]['trips'][0]['bookings'], 'id'))]['status'] === 'Reserved');
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0711111111', 'booking_ids' => ['bk6']]); sleep(2);
[$c, $r] = call('GET', 'payments.php?action=status&id=' . $r['payment']['id']); ok('insufficient funds -> failed with message', $r['payment']['status'] === 'failed' && str_contains($r['payment']['message'], 'Insufficient'));
// card
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'card', 'booking_ids' => ['bk6'], 'card' => ['number' => '4111 1111 1111 1112', 'exp' => '12/30', 'cvv' => '123']]); ok('invalid card number (Luhn) rejected', $c === 400);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'card', 'booking_ids' => ['bk6'], 'card' => ['number' => '4111 1111 1111 1111', 'exp' => '01/20', 'cvv' => '123']]); ok('expired card rejected', $c === 400);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'card', 'booking_ids' => ['bk6'], 'card' => ['number' => '4111 1111 1111 1111', 'exp' => '12/30', 'cvv' => '123']]); $p3 = $r['payment'] ?? [];
ok('card payment created, only last 4 digits kept', ($p3['phone'] ?? '') === '**** 1111'); sleep(2);
[$c, $r] = call('GET', 'payments.php?action=status&id=' . $p3['id']); ok('card payment succeeds', $r['payment']['status'] === 'success' && str_starts_with($r['payment']['receipt'], 'CD'));

// ---- history, receipt, privacy
[$c, $r] = call('GET', 'payments.php?action=history'); ok('history lists all attempts, newest first', count($r['payments']) === 4 && $r['payments'][0]['id'] === $p3['id']);
[$c, $r] = call('GET', 'payments.php?action=receipt&id=' . $pay['id']); ok('receipt has payer + items', $r['payment']['payer']['name'] === 'Pay Tester' && count($r['payment']['items']) === 1);
$jar2 = sys_get_temp_dir() . '/tic-pay2-' . getmypid() . '.txt';
call('POST', 'auth.php?action=register', ['name' => 'Other', 'email' => 'o' . $email, 'password' => 'secret1'], $jar2);
[$c, $r] = call('GET', 'payments.php?action=receipt&id=' . $pay['id'], null, $jar2); ok("another user cannot see someone's payment", $c === 404);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0712345678'], $jar2); ok("another user cannot pay for someone's trip", $c === 404);
[$c, $r] = call('POST', 'payments.php?action=callback&secret=wrong', ['Body' => []]); ok('callback rejects a wrong secret', $c === 403);
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tid, 'method' => 'mpesa', 'phone' => '0712345678'], null); // unauth check below

// ================= SEATS =================
function mkTrip($jf, $name, $bookings) { return call('POST', 'trips.php', ['name' => $name, 'destination' => 'Bali', 'start' => '2026-12-10', 'end' => '2026-12-16', 'travellers' => 2, 'budget' => 900000, 'activities' => [], 'expenses' => [], 'bookings' => $bookings], $jf); }
function flightBk($g, $id, $seats) { return ['id' => $id, 'type' => 'flight', 'title' => 'Flight', 'date' => $g['date'], 'time' => $g['depart'], 'ref' => $g['ref'], 'unit_price' => $g['unit_price'], 'qty' => 2, 'quote' => $g['quote'], 'seats' => $seats]; }
[$c, $gl] = call('GET', 'flights.php?from=NBO&to=DPS&date=2026-12-10'); $g = $gl['flights'][0];
[$c, $sm] = call('GET', 'seats.php?ref=' . urlencode($g['ref']));
ok('seat map: 30 rows, taken seats, row prices', $c === 200 && count($sm['rows']) === 30 && $sm['rows'][0]['t'] === 'extra' && $sm['rows'][0]['p'] === 4500 && $sm['rows'][11]['t'] === 'exit' && count($sm['taken']) > 20, json_encode(array_slice($sm, 0, 2)));
[$c, $x] = call('GET', 'seats.php?ref=' . urlencode('HOTEL|x')); ok('seat map only for flights', $c === 400);
$free = []; foreach ($sm['rows'] as $row) foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $l) if (!in_array($row['n'] . $l, $sm['taken'], true) && $row['n'] <= 9) $free[] = $row['n'] . $l;
$pair = [$free[0], $free[1]]; $fee = array_sum(array_map(fn($s) => $sm['rows'][(int)$s - 1]['p'], $pair));
[$c, $r] = mkTrip(null, 'Seat trip A', [flightBk($g, 'sa1', implode(',', $pair))]); $b = $r['trip']['bookings'][0] ?? [];
ok('seats saved; fee + fare calculated by the server', $c === 200 && $b['seats'] === implode(',', $pair) && $b['seat_fee'] == $fee && $b['price'] == $g['unit_price'] * 2 + $fee, json_encode($b));
[$c, $r] = mkTrip(null, 'Seat trip bad1', [flightBk($g, 'sx1', 'Z9,A1')]); ok('invalid seat code rejected', $c === 400);
[$c, $r] = mkTrip(null, 'Seat trip bad2', [flightBk($g, 'sx2', $sm['taken'][0])]); ok('already-taken seat rejected (409)', $c === 409);
[$c, $r] = mkTrip(null, 'Seat trip bad3', [flightBk($g, 'sx3', $free[2] . ',' . $free[3] . ',' . $free[4])]); ok('more seats than travellers rejected', $c === 400);
$hb = ['id' => 'hx', 'type' => 'hotel', 'title' => 'H', 'date' => '2026-12-10', 'time' => '15:00', 'ref' => $ho['results'][0]['ref'], 'unit_price' => $ho['results'][0]['unit_price'], 'qty' => 1, 'quote' => $ho['results'][0]['quote'], 'seats' => '1A'];
[$c, $r] = mkTrip(null, 'Seat trip hotel', [$hb]); ok('seats are ignored on non-flight bookings', ($r['trip']['bookings'][0]['seats'] ?? 'x') === '' && $r['trip']['bookings'][0]['price'] == $hb['unit_price']);

// seat race: B reserves the same seats first, A pays first -> B must not be able to pay or save those seats
$trA = call('GET', 'trips.php')[1]['trips']; $tripA = null; foreach ($trA as $t) if ($t['name'] === 'Seat trip A') $tripA = $t;
[$c, $r] = mkTrip($jar2, 'Seat trip B', [flightBk($g, 'sb1', implode(',', $pair))]); $tripB = $r['trip'];
ok('another user can still reserve the same seats while unpaid', $c === 200 && $tripB['bookings'][0]['status'] === 'Reserved');
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tripA['id'], 'method' => 'mpesa', 'phone' => '0712345678']); $pa = $r['payment']; sleep(2);
[$c, $r] = call('GET', 'payments.php?action=status&id=' . $pa['id']); ok('first passenger pays for the seats (receipt shows seats)', $r['payment']['status'] === 'success' && str_contains($r['payment']['items'][0]['detail'], $pair[0]), json_encode($r['payment']['items'] ?? ''));
[$c, $r] = call('POST', 'payments.php?action=create', ['trip_id' => $tripB['id'], 'method' => 'mpesa', 'phone' => '0722222222'], $jar2); ok('second passenger is told the seat was just taken (409)', $c === 409 && str_contains($r['error'], 'just taken'), json_encode($r));
$tb = $tripB; $tb['bookings'][0]['seats'] = implode(',', $pair); [$c, $r] = call('POST', 'trips.php', $tb, $jar2); ok('re-saving paid seats is rejected too', $c === 409);
$tb['bookings'][0]['seats'] = ''; [$c, $r] = call('POST', 'trips.php', $tb, $jar2); ok('after clearing the seats the booking is payable again', $c === 200 && $r['trip']['bookings'][0]['status'] === 'Reserved');

// ================= BUDGET PLANNER =================
$q = 'destination=Bali&start=2026-12-10&end=2026-12-16&travellers=1';
[$c, $pl] = call('GET', "plan.php?$q&available=150000");
ok('planner: package fits inside the budget', $c === 200 && $pl['feasible'] && $pl['package']['total'] <= 150000 && $pl['package']['left'] >= 0 && count($pl['package']['items']) >= 3, json_encode($pl['package']['total'] ?? ''));
ok('planner: flights (both ways) + hotel always included', count(array_intersect(['flight_out', 'flight_ret', 'hotel'], array_column($pl['package']['items'], 'cat'))) === 3);
$bks = []; foreach ($pl['package']['items'] as $i => $it) { $bks[] = $it['booking'] + ['id' => "pl$i"]; }
[$c, $r] = mkTrip(null, 'Planner trip', $bks); $sum = array_sum(array_column($r['trip']['bookings'], 'price'));
ok('planner items carry valid signed prices (server accepts them as Reserved)', $c === 200 && count(array_filter($r['trip']['bookings'], fn($b) => $b['status'] === 'Reserved')) === count($bks) && $sum == $pl['package']['total'], "$sum vs " . ($pl['package']['total'] ?? ''));
[$c, $pl2] = call('GET', "plan.php?$q&available=10000"); ok('planner: too little money -> not feasible, says how much more is needed', $c === 200 && !$pl2['feasible'] && $pl2['shortfall'] > 0);
[$c, $pl3] = call('GET', "plan.php?$q&available=2000000"); ok('planner: bigger budget -> bigger/better package, activities + restaurants added', $pl3['feasible'] && $pl3['package']['total'] >= $pl['package']['total'] && count($pl3['package']['items']) >= count($pl['package']['items']));
[$c, $pl4] = call('GET', "plan.php?$q&available=150000&have_flights=2&have_hotel=1&have_activity=2&have_restaurant=2"); ok('planner: everything already booked -> complete', $pl4['complete'] === true && count($pl4['package']['items']) === 0);
[$c, $x] = call('GET', 'plan.php?destination=&start=x&end=y'); ok('planner validates input', $c === 400);

// ================= SAMPLE TRIPS =================
[$c, $r] = call('POST', 'seed.php', ['kind' => 'planning']); $gr = null; foreach ($r['trips'] ?? [] as $t) if ($t['name'] === 'Greece Adventure') $gr = $t;
$grF = $gr ? array_values(array_filter($gr['bookings'], fn($b) => $b['type'] === 'flight'))[0] : [];
ok('sample 1 (planning): reserved items, flight with seats', $c === 200 && $gr && $grF['status'] === 'Reserved' && count(explode(',', $grF['seats'])) === 2 && count(array_filter($gr['bookings'], fn($b) => $b['status'] === 'Reserved')) === 4);
[$c, $r] = call('POST', 'seed.php', ['kind' => 'planning']); ok('loading the same sample twice -> 409', $c === 409);
[$c, $r] = call('POST', 'seed.php', ['kind' => 'complete']); $ba = null; foreach ($r['trips'] ?? [] as $t) if ($t['name'] === 'Bali Escape') $ba = $t;
$st = $ba ? array_count_values(array_column($ba['bookings'], 'status')) : [];
ok('sample 2 (complete): everything Paid (+1 booked elsewhere), nothing awaiting payment', $c === 200 && ($st['Paid'] ?? 0) === 7 && ($st['Reserved'] ?? 0) === 0 && ($st['Confirmed'] ?? 0) === 1, json_encode($st));
ok('sample 2: payment expenses logged + within budget', $ba && count(array_filter($ba['expenses'], fn($e) => str_starts_with($e['id'], 'pay_'))) >= 4 && array_sum(array_column($ba['expenses'], 'amount')) <= $ba['budget']);
[$c, $h2] = call('GET', 'payments.php?action=history'); $seedPays = array_filter($h2['payments'], fn($p) => $p['trip_name'] === 'Bali Escape');
ok('sample 2: two receipts (M-Pesa + card) in Payments', count($seedPays) === 2 && array_unique(array_column($seedPays, 'status')) === ['success'] && count(array_unique(array_column($seedPays, 'method'))) === 2);
[$c, $r] = call('DELETE', 'trips.php?id=' . $ba['id']); ok('the paid sample trip cannot be deleted', $c === 409);
[$c, $r] = call('POST', 'seed.php', ['kind' => 'nonsense']); ok('unknown sample kind rejected', $c === 400);

call('POST', 'auth.php?action=logout'); [$c, $r] = call('GET', 'payments.php?action=history'); ok('payments require login', $c === 401);
@unlink($jar); @unlink($jar2);
echo "\nPayments: $P passed, $F failed\n"; exit($F ? 1 : 0);
