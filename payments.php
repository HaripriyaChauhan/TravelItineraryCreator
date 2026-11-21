<?php
// PAYMENTS (Owner: Tilak / Paul)
//   M-Pesa  : Safaricom Daraja "STK Push" (Lipa na M-Pesa Online) - real in sandbox/live mode
//   Airtel Money + Card : DEMO ONLY (simulated). In production use the Airtel Money API / a hosted card checkout (Pesapal, Flutterwave...)
// Flow: create (STK push sent) -> browser polls "status" -> Safaricom calls "callback" (and/or we query) -> bookings become Paid, expenses logged.
// Actions: config | create | status | history | receipt | callback
require 'config.php';
$cfg = pay_cfg();
$a = $_GET['action'] ?? '';
const TYPE_CAT = ['flight' => 'Flights', 'hotel' => 'Accommodation', 'activity' => 'Activities', 'restaurant' => 'Food'];
const METHOD_NAME = ['mpesa' => 'M-Pesa', 'airtel' => 'Airtel Money', 'card' => 'Card'];

// ---------- Safaricom Daraja ----------
function http_json($method, $url, $headers, $payload = null) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method]);
  if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  $res = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($res === false) return ['ok' => false, 'code' => 0, 'json' => null, 'error' => $err];
  return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => json_decode($res, true), 'error' => ''];
}
function mpesa_base($cfg) { return $cfg['mode'] === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke'; }
function mpesa_token($cfg) {
  $m = $cfg['mpesa'];
  $r = http_json('GET', mpesa_base($cfg) . '/oauth/v1/generate?grant_type=client_credentials', ['Authorization: Basic ' . base64_encode($m['consumer_key'] . ':' . $m['consumer_secret'])]);
  return $r['json']['access_token'] ?? null;
}
function mpesa_stk_push($cfg, $phone, $amount, $accountRef) {
  $m = $cfg['mpesa'];
  if (!$m['consumer_key'] || !$m['consumer_secret'] || !$m['callback_base']) return ['ok' => false, 'error' => 'M-Pesa is not configured yet. Fill in api/payment_config.php (keys + callback URL).'];
  $tok = mpesa_token($cfg);
  if (!$tok) return ['ok' => false, 'error' => 'Could not authenticate with Safaricom. Check the consumer key and secret.'];
  $ts = date('YmdHis');
  $r = http_json('POST', mpesa_base($cfg) . '/mpesa/stkpush/v1/processrequest', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], [
    'BusinessShortCode' => $m['shortcode'], 'Password' => base64_encode($m['shortcode'] . $m['passkey'] . $ts), 'Timestamp' => $ts,
    'TransactionType' => $m['transaction_type'], 'Amount' => (int)$amount, 'PartyA' => $phone, 'PartyB' => $m['party_b'] ?: $m['shortcode'], 'PhoneNumber' => $phone,
    'CallBackURL' => $m['callback_base'] . '?action=callback&secret=' . urlencode($cfg['callback_secret']),
    'AccountReference' => substr($accountRef, 0, 12), 'TransactionDesc' => 'Trip booking',
  ]);
  $j = $r['json'] ?? [];
  if ($r['ok'] && ($j['ResponseCode'] ?? '') === '0') return ['ok' => true, 'checkout_id' => $j['CheckoutRequestID'], 'merchant_id' => $j['MerchantRequestID']];
  return ['ok' => false, 'error' => $j['errorMessage'] ?? ($j['ResponseDescription'] ?? ($r['error'] ?: 'Safaricom rejected the request'))];
}
function mpesa_query($cfg, $checkoutId) {
  $m = $cfg['mpesa']; $tok = mpesa_token($cfg); if (!$tok) return null; $ts = date('YmdHis');
  $r = http_json('POST', mpesa_base($cfg) . '/mpesa/stkpushquery/v1/query', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'],
    ['BusinessShortCode' => $m['shortcode'], 'Password' => base64_encode($m['shortcode'] . $m['passkey'] . $ts), 'Timestamp' => $ts, 'CheckoutRequestID' => $checkoutId]);
  return $r['json'];   // has "ResultCode" once the customer has answered; before that Daraja answers with an error ("being processed")
}
function mpesa_outcome($code, $desc) {
  return match ((string)$code) {
    '0' => ['success', 'Payment received'], '1032' => ['cancelled', 'Request cancelled by user'], '1' => ['failed', 'Insufficient M-Pesa balance'],
    '2001' => ['failed', 'Wrong PIN entered'], '1037' => ['expired', 'No response from the phone (timed out)'], default => ['failed', $desc ?: 'Payment failed'],
  };
}

// ---------- demo simulation ----------
function luhn($n) { $s = 0; $alt = false; for ($i = strlen($n) - 1; $i >= 0; $i--) { $d = (int)$n[$i]; if ($alt) { $d *= 2; if ($d > 9) $d -= 9; } $s += $d; $alt = !$alt; } return $s % 10 === 0; }
function mock_outcome($method, $phone, $cardNo) {
  if ($method === 'card') return substr($cardNo, -4) === '0002' ? 'declined' : 'success';
  return ['0000' => 'cancelled', '1111' => 'insufficient', '2222' => 'wrongpin'][substr($phone, -4)] ?? 'success';
}
const MOCK_RESULT = ['cancelled' => ['cancelled', 'Request cancelled by user'], 'insufficient' => ['failed', 'Insufficient funds in your account'],
  'wrongpin' => ['failed', 'Wrong PIN entered too many times'], 'declined' => ['failed', 'Card declined by the issuing bank']];
function mock_receipt($method) { return ['mpesa' => 'S', 'airtel' => 'AM', 'card' => 'CD'][$method] . strtoupper(substr(bin2hex(random_bytes(6)), 0, $method === 'mpesa' ? 9 : 8)); }

// ---------- payment records ----------
function pay_row($db, $id, $uid = null) {
  $s = $db->prepare('SELECT * FROM payments WHERE id = ?' . ($uid ? ' AND user_id = ?' : '')); $s->execute($uid ? [$id, $uid] : [$id]); return $s->fetch();
}
function pay_public($p) {
  $ph = (string)$p['phone']; if (preg_match('/^254\d{9}$/', $ph)) $ph = substr($ph, 0, 6) . '***' . substr($ph, -3);
  return ['id' => $p['id'], 'reference' => 'TIC-' . strtoupper(substr($p['id'], 0, 8)), 'trip_id' => $p['trip_id'], 'trip_name' => $p['trip_name'], 'method' => $p['method'],
    'phone' => $ph, 'amount' => (float)$p['amount'], 'currency' => $p['currency'], 'status' => $p['status'], 'receipt' => $p['receipt'], 'message' => $p['result_desc'],
    'items' => json_decode($p['items'], true), 'created_at' => (int)$p['created_at'], 'paid_at' => $p['paid_at'] ? (int)$p['paid_at'] : null];
}
// Idempotent: only the first success/failure for a payment is applied.
function finalize($db, $p, $status, $receipt, $msg) {
  $db->beginTransaction();
  if ($status !== 'success') {
    $db->prepare("UPDATE payments SET status = ?, result_desc = ? WHERE id = ? AND status = 'pending'")->execute([$status, $msg, $p['id']]);
    $db->commit(); return;
  }
  $u = $db->prepare("UPDATE payments SET status = 'success', receipt = ?, result_desc = ?, paid_at = ? WHERE id = ? AND status IN ('pending','expired')");
  $u->execute([$receipt, $msg, time(), $p['id']]);
  if ($u->rowCount() === 0) { $db->commit(); return; }
  $items = json_decode($p['items'], true); $cats = [];
  foreach ($items as $it) {
    $db->prepare("UPDATE bookings SET status = 'Paid', payment_id = ? WHERE id = ? AND trip_id = ?")->execute([$p['id'], $it['id'], $p['trip_id']]);
    $c = TYPE_CAT[$it['type']] ?? 'Activities'; $cats[$c] = ($cats[$c] ?? 0) + $it['amount'];
  }
  $i = 0;
  foreach ($cats as $cat => $sum)
    $db->prepare('INSERT INTO expenses (id, trip_id, cat, title, amount, exp_date) VALUES (?,?,?,?,?,?)')
       ->execute(['pay_' . substr($p['id'], 0, 8) . (++$i), $p['trip_id'], $cat, 'Paid via ' . METHOD_NAME[$p['method']] . ' (' . $cat . ')', $sum, date('Y-m-d')]);
  if (preg_match('/^254\d{9}$/', (string)$p['phone']))   // remember the number for next time
    $db->prepare("UPDATE users SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = '')")->execute([$p['phone'], $p['user_id']]);
  $db->commit();
}

try {
  // ----- Safaricom calls this URL (no login session!). Protected by the secret in the URL + matching amount. -----
  if ($a === 'callback') {
    if (!hash_equals($cfg['callback_secret'], (string)($_GET['secret'] ?? ''))) out(['ResultCode' => 1, 'ResultDesc' => 'Rejected'], 403);
    $d = body()['Body']['stkCallback'] ?? null; $db = pdo();
    if ($d) {
      $s = $db->prepare('SELECT * FROM payments WHERE provider_ref = ?'); $s->execute([$d['CheckoutRequestID'] ?? '']); $p = $s->fetch();
      if ($p) {
        $meta = []; foreach ($d['CallbackMetadata']['Item'] ?? [] as $it) $meta[$it['Name']] = $it['Value'] ?? null;
        [$st, $msg] = mpesa_outcome($d['ResultCode'] ?? 1, $d['ResultDesc'] ?? '');
        if ($st === 'success' && (int)round($meta['Amount'] ?? 0) !== (int)$p['amount']) [$st, $msg] = ['failed', 'Amount paid does not match the order amount - contact support'];
        finalize($db, $p, $st, $meta['MpesaReceiptNumber'] ?? null, $msg);
        if ($st === 'success') $db->prepare("UPDATE payments SET receipt = ? WHERE id = ? AND (receipt IS NULL OR receipt = '')")->execute([$meta['MpesaReceiptNumber'] ?? null, $p['id']]);
      }
    }
    out(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
  }

  $uid = require_login(); $db = pdo();

  if ($a === 'config') {
    $mock = $cfg['mode'] === 'mock';
    out(['mode' => $cfg['mode'], 'mpesa_max' => MPESA_MAX_TX, 'mock_delay' => $cfg['mock_delay'],
      'methods' => ['mpesa' => true, 'airtel' => $mock, 'card' => $mock]]);
  }

  if ($a === 'history') {
    $s = $db->prepare('SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 50'); $s->execute([$uid]);
    out(['payments' => array_map('pay_public', $s->fetchAll())]);
  }

  if ($a === 'receipt' || $a === 'status') {
    $p = pay_row($db, $_GET['id'] ?? '', $uid);
    if (!$p) out(['error' => 'Payment not found'], 404);
    if ($a === 'status' && $p['status'] === 'pending') {
      $age = time() - (int)$p['created_at'];
      if ($p['method'] !== 'mpesa' || $cfg['mode'] === 'mock') {                       // simulated providers
        $delay = (int)(getenv('TIC_MOCK_DELAY') !== false && getenv('TIC_MOCK_DELAY') !== '' ? getenv('TIC_MOCK_DELAY') : $cfg['mock_delay']);
        if ($age >= $delay) {
          $o = substr((string)$p['merchant_ref'], 5);
          if ($o === 'success') finalize($db, $p, 'success', mock_receipt($p['method']), 'Payment received');
          else { [$st, $msg] = MOCK_RESULT[$o] ?? ['failed', 'Payment failed']; finalize($db, $p, $st, null, $msg); }
        }
      } elseif ($age >= 5) {                                                           // real M-Pesa: ask Safaricom if the callback has not arrived
        $j = mpesa_query($cfg, $p['provider_ref']);
        if ($j && isset($j['ResultCode'])) { [$st, $msg] = mpesa_outcome($j['ResultCode'], $j['ResultDesc'] ?? ''); finalize($db, $p, $st, null, $msg); }
        elseif ($age > 150) finalize($db, $p, 'expired', null, 'No response from your phone. If money was deducted it will be matched automatically.');
      }
      $p = pay_row($db, $p['id'], $uid);
    }
    $out = pay_public($p);
    if ($a === 'receipt') { $s = $db->prepare('SELECT name, email FROM users WHERE id = ?'); $s->execute([$uid]); $out['payer'] = $s->fetch(); }
    out(['payment' => $out]);
  }

  if ($a === 'create') {
    $b = body(); $method = $b['method'] ?? '';
    if (!isset(METHOD_NAME[$method])) out(['error' => 'Choose a payment method'], 400);
    if ($cfg['mode'] !== 'mock' && $method !== 'mpesa') out(['error' => METHOD_NAME[$method] . ' is not enabled yet. Please pay with M-Pesa.'], 501);
    $s = $db->prepare('SELECT * FROM trips WHERE id = ? AND user_id = ?'); $s->execute([$b['trip_id'] ?? '', $uid]); $trip = $s->fetch();
    if (!$trip) out(['error' => 'Trip not found'], 404);

    // Amount comes from the DATABASE (signed, server-priced bookings) - never from the browser.
    $s = $db->prepare("SELECT id, type, title, price, ref, seats FROM bookings WHERE trip_id = ? AND status = 'Reserved' AND price > 0"); $s->execute([$trip['id']]);
    $ids = isset($b['booking_ids']) && is_array($b['booking_ids']) ? $b['booking_ids'] : null; $items = []; $total = 0;
    foreach ($s->fetchAll() as $r) if ($ids === null || in_array($r['id'], $ids, true)) {
      if ($r['type'] === 'flight' && $r['seats']) {     // a seat may have been bought by someone else since it was chosen
        foreach (array_intersect(explode(',', $r['seats']), seats_taken_by_others($db, $r['ref'], $r['id'])) as $c)
          out(['error' => "Seat $c on " . $r['title'] . ' was just taken by another passenger. Please choose new seats for this flight.', 'seat_conflict' => $r['id']], 409);
      }
      $items[] = ['id' => $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'amount' => (float)$r['price'], 'detail' => $r['seats'] ? 'Seats ' . str_replace(',', ', ', $r['seats']) : ''];
      $total += $r['price'];
    }
    if (!$items) out(['error' => 'There is nothing to pay for. Add flights, hotels or activities first.'], 400);
    $amount = (int)ceil($total);
    if ($method === 'mpesa' && $amount > MPESA_MAX_TX) out(['error' => 'M-Pesa allows up to KES ' . number_format(MPESA_MAX_TX) . ' per transaction. Select fewer items and pay in parts, or use another method.'], 400);

    $phone = null; $cardNo = '';
    if ($method === 'card') {
      $c = $b['card'] ?? []; $cardNo = preg_replace('/\D/', '', $c['number'] ?? '');
      if (strlen($cardNo) < 13 || strlen($cardNo) > 19 || !luhn($cardNo)) out(['error' => 'The card number is not valid'], 400);
      if (!preg_match('/^(\d{2})\/(\d{2})$/', trim($c['exp'] ?? ''), $mm) || $mm[1] < 1 || $mm[1] > 12 || ((int)('20' . $mm[2]) * 12 + (int)$mm[1]) < ((int)date('Y') * 12 + (int)date('n'))) out(['error' => 'The card has expired or the date is invalid (use MM/YY)'], 400);
      if (!preg_match('/^\d{3,4}$/', $c['cvv'] ?? '')) out(['error' => 'Enter the 3 or 4 digit security code'], 400);
      $phone = '**** ' . substr($cardNo, -4);                                         // card data is NEVER stored - only the last 4 digits
    } else {
      $phone = norm_phone($b['phone'] ?? '');
      if (!$phone) out(['error' => 'Enter a valid Kenyan phone number, e.g. 0712 345 678'], 400);
    }

    // one payment at a time per trip (a second STK prompt while the first is open would confuse the customer)
    $s = $db->prepare("SELECT * FROM payments WHERE user_id = ? AND trip_id = ? AND status = 'pending'"); $s->execute([$uid, $trip['id']]);
    foreach ($s->fetchAll() as $old) {
      $age = time() - (int)$old['created_at'];
      if ($age > 150) finalize($db, $old, 'expired', null, 'No response from your phone');
      else out(['error' => 'A payment for this trip is already waiting for approval. Please wait about ' . (150 - $age) . ' seconds, or check your phone.', 'retry_after' => 150 - $age], 409);
    }

    $pid = new_id(); $provider = 'mock_' . $pid; $merchant = 'mock:' . mock_outcome($method, (string)$phone, $cardNo);
    if ($method === 'mpesa' && $cfg['mode'] !== 'mock') {
      $r = mpesa_stk_push($cfg, $phone, $amount, 'TIC' . substr($pid, 0, 8));
      if (!$r['ok']) out(['error' => $r['error']], 502);
      $provider = $r['checkout_id']; $merchant = $r['merchant_id'];
    }
    $db->prepare('INSERT INTO payments (id, user_id, trip_id, trip_name, method, phone, amount, currency, status, provider_ref, merchant_ref, items, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$pid, $uid, $trip['id'], $trip['name'], $method, $phone, $amount, 'KES', 'pending', $provider, $merchant, json_encode($items), time()]);
    out(['payment' => pay_public(pay_row($db, $pid, $uid))]);
  }
  out(['error' => 'Unknown action'], 400);
} catch (PDOException $e) {
  if (isset($db) && $db->inTransaction()) $db->rollBack();
  out(['error' => 'Database error: ' . $e->getMessage()], 500);
}
