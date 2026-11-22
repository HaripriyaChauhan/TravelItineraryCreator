<?php
// BUDGET PLANNER: "I have KES X left - what can I get for it?"  (Owner: Paul / Tilak)
// Finds the best combination of outbound flight + return flight + hotel + up to 2 activities + up to 2 restaurants whose TOTAL fits the
// available money (flights/hotel are essential and chosen first; activities/restaurants only if money is left). If even the cheapest
// essentials do not fit, it says exactly how much more is needed. Every suggested item comes with a signed price, ready to add to the trip.
require 'catalog.php';
require 'destinations.php';
require_login();

$city = trim(explode(',', (string)($_GET['destination'] ?? ''))[0]);
$start = (string)($_GET['start'] ?? ''); $end = (string)($_GET['end'] ?? '');
if ($city === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) out(['error' => 'The trip needs a destination and valid dates'], 400);
$trav = max(1, min(20, (int)($_GET['travellers'] ?? 1))); $avail = max(0, (int)($_GET['available'] ?? 0));
$home = strtoupper(substr((string)($_GET['home'] ?? 'NBO'), 0, 3));
$haveF = (int)($_GET['have_flights'] ?? 0); $haveH = (int)($_GET['have_hotel'] ?? 0); $haveA = (int)($_GET['have_activity'] ?? 0); $haveR = (int)($_GET['have_restaurant'] ?? 0);
$days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1; $nights = max(1, $days - 1);
$hit = dest_lookup($city); $iata = $hit ? $hit[1]['iata'] : '';
$flightsPossible = $iata !== '' && $iata !== $home;

function hhmm($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
function tmin($t) { [$h, $m] = array_map('intval', explode(':', $t)); return $h * 60 + $m; }
function clamp_time($pref, $opens, $closes, $dur) { $lo = tmin($opens); $hi = tmin($closes) - $dur; return hhmm(max($lo, min($pref, max($lo, $hi)))); }
function day_n($start, $n) { return date('Y-m-d', strtotime($start) + $n * 86400); }
function k($n) { return 'KES ' . number_format($n); }
$mid = $days >= 3 ? range(1, $days - 2) : [0];

// ---------- candidate options ----------
function opt($cat, $item, $cost, $q, $booking) { return ['cat' => $cat, 'cost' => (int)$cost, 'q' => $q, 'rating' => $item['rating'] ?? null, 'booking' => $booking]; }
function flight_booking($f, $trav) {
  return ['type' => 'flight', 'title' => "Flight {$f['from']}→{$f['to']} ({$f['number']})", 'date' => $f['date'], 'time' => $f['depart'], 'duration' => $f['duration'],
    'detail' => "{$f['airline']} · $trav traveller" . ($trav > 1 ? 's' : '') . ' × ' . k($f['unit_price']), 'status' => 'Reserved', 'ref' => $f['ref'], 'unit_price' => $f['unit_price'], 'qty' => $trav, 'quote' => $f['quote'], 'seats' => '', 'opens' => '', 'closes' => ''];
}
$fo = []; $fr = [];
if ($flightsPossible) {
  foreach (catalog_flights($home, $iata, $start) as $f) $fo[] = opt('flight_out', $f, $f['unit_price'] * $trav, -($f['duration'] / 60) - 2 * $f['stops'], flight_booking($f, $trav));
  foreach (catalog_flights($iata, $home, $end) as $f) $fr[] = opt('flight_ret', $f, $f['unit_price'] * $trav, -($f['duration'] / 60) - 2 * $f['stops'], flight_booking($f, $trav));
}
function hotel_opts($city, $start, $n) {
  $ho = [];
  foreach (catalog_venues('hotel', $city) as $h)
    $ho[] = opt('hotel', $h, $h['unit_price'] * $n, $h['rating'] * 10, ['type' => 'hotel', 'title' => $h['name'], 'date' => $start, 'time' => '15:00', 'duration' => 30,
      'detail' => "$n night" . ($n > 1 ? 's' : '') . ' × ' . k($h['unit_price']), 'status' => 'Reserved', 'ref' => $h['ref'], 'unit_price' => $h['unit_price'], 'qty' => $n, 'quote' => $h['quote'], 'seats' => '', 'opens' => '', 'closes' => '']);
  return $ho;
}
function venue_opts($type, $city, $trav, $start, $mid, $pref, $w, $shift, $days) {
  $out = []; foreach (catalog_venues($type, $city) as $v) {
    $day = $mid[$shift % count($mid)];
    $out[] = opt($type, $v, $v['unit_price'] * $trav, $v['rating'] * $w, ['type' => $type, 'title' => $v['name'], 'date' => day_n($start, $day), 'time' => clamp_time($pref, $v['opens'], $v['closes'], $v['duration']), 'duration' => $v['duration'],
      'detail' => "$trav × " . k($v['unit_price']) . ' per person', 'status' => 'Reserved', 'ref' => $v['ref'], 'unit_price' => $v['unit_price'], 'qty' => $trav, 'quote' => $v['quote'], 'seats' => '', 'opens' => $v['opens'], 'closes' => $v['closes']]);
  } return $out;
}
$ac = venue_opts('activity', $city, $trav, $start, $mid, 600, 3, 0, $days);
$re = venue_opts('restaurant', $city, $trav, $start, $mid, 1170, 2, 1, $days);
usort($ac, fn($a, $b) => $b['q'] <=> $a['q']); usort($re, fn($a, $b) => $b['q'] <=> $a['q']);

function subsets($opts, $max) {      // all picks of 0..$max items from the best 5
  $opts = array_slice($opts, 0, 5); $res = [['idx' => [], 'cost' => 0, 'q' => 0]]; $n = count($opts);
  if ($max >= 1) for ($i = 0; $i < $n; $i++) $res[] = ['idx' => [$i], 'cost' => $opts[$i]['cost'], 'q' => $opts[$i]['q']];
  if ($max >= 2) for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) $res[] = ['idx' => [$i, $j], 'cost' => $opts[$i]['cost'] + $opts[$j]['cost'], 'q' => $opts[$i]['q'] + $opts[$j]['q']];
  return $res;
}
$needOut = $flightsPossible && $haveF < 1; $needRet = $flightsPossible && $haveF < 2; $needHotel = $haveH < 1;
$maxA = max(0, 2 - $haveA); $maxR = max(0, 2 - $haveR);
$outs = $needOut ? $fo : [null]; $rets = $needRet ? $fr : [null];
$actC = subsets($ac, $maxA); $resC = subsets($re, $maxR);
$c = fn($o) => $o ? $o['cost'] : 0; $q = fn($o) => $o ? $o['q'] : 0;

$best = null; $usedNights = $nights;
for ($n = $nights; $n >= 1 && $best === null; $n--) {          // full stay first; if it does not fit, try shorter stays
  $ho = hotel_opts($city, $start, $n); $hots = $needHotel ? $ho : [null];
  foreach ($outs as $o) foreach ($rets as $r) foreach ($hots as $h) {
    $base = $c($o) + $c($r) + $c($h); if ($base > $avail) continue; $bq = $q($o) + $q($r) + $q($h);
    foreach ($actC as $a) { $t1 = $base + $a['cost']; if ($t1 > $avail) continue;
      foreach ($resC as $e) { $tot = $t1 + $e['cost']; if ($tot > $avail) continue; $qq = $bq + $a['q'] + $e['q'];
        if ($best === null || $qq > $best['q'] || ($qq == $best['q'] && $tot < $best['total'])) $best = ['q' => $qq, 'total' => $tot, 'o' => $o, 'r' => $r, 'h' => $h, 'a' => $a, 'e' => $e];
      } }
  }
  if ($best !== null) $usedNights = $n;
  if (!$needHotel) break;
}
if ($best === null) { $ho = hotel_opts($city, $start, $nights); $usedNights = $nights; }
$feasible = $best !== null; $shortfall = 0; $items = [];
if (!$feasible) {   // cheapest essentials, to tell the traveller how much is missing
  $cheap = fn($l) => $l ? array_reduce($l, fn($m, $x) => $m === null || $x['cost'] < $m['cost'] ? $x : $m) : null;
  $o = $needOut ? $cheap($fo) : null; $r = $needRet ? $cheap($fr) : null; $h = $needHotel ? $cheap($ho) : null;
  $best = ['o' => $o, 'r' => $r, 'h' => $h, 'a' => ['idx' => []], 'e' => ['idx' => []], 'total' => $c($o) + $c($r) + $c($h)]; $shortfall = max(0, $best['total'] - $avail);
}
$labels = ['flight_out' => 'Outbound flight', 'flight_ret' => 'Return flight', 'hotel' => 'Hotel', 'activity' => 'Activity', 'restaurant' => 'Restaurant'];
foreach ([$best['o'], $best['r'], $best['h']] as $x) if ($x) $items[] = $x;
foreach ($best['a']['idx'] as $i) $items[] = $ac[$i];
foreach ($best['e']['idx'] as $i) $items[] = $re[$i];
$pkgCost = array_sum(array_column($items, 'cost'));
$items = array_map(fn($x) => ['cat' => $x['cat'], 'label' => $labels[$x['cat']], 'cost' => $x['cost'], 'rating' => $x['rating'], 'booking' => $x['booking']], $items);

// ---------- alternatives that still fit the money (swap a flight/hotel, or add one more activity / meal) ----------
$cats = []; $inPkg = fn($cat, $o) => count(array_filter($items, fn($i) => $i['cat'] === $cat && $i['booking']['ref'] === $o['booking']['ref'])) > 0;
foreach (['flight_out' => [$needOut, $fo], 'flight_ret' => [$needRet, $fr], 'hotel' => [$needHotel, $ho], 'activity' => [$maxA > 0, $ac], 'restaurant' => [$maxR > 0, $re]] as $cat => [$need, $list]) {
  if (!$need || !$list) continue;
  $inCat = array_sum(array_map(fn($i) => $i['cost'], array_filter($items, fn($i) => $i['cat'] === $cat)));
  $opts = [];
  foreach ($list as $o) {
    $isExtra = in_array($cat, ['activity', 'restaurant']);
    $fits = $isExtra ? ($pkgCost + ($inPkg($cat, $o) ? 0 : $o['cost']) <= $avail) : ($pkgCost - $inCat + $o['cost'] <= $avail);
    if ($feasible && !$fits) continue; if (!$feasible && $o['cost'] > $avail) continue;
    $opts[] = ['cost' => $o['cost'], 'rating' => $o['rating'], 'recommended' => $inPkg($cat, $o), 'booking' => $o['booking']];
  }
  usort($opts, fn($a, $b) => ($b['recommended'] <=> $a['recommended']) ?: $a['cost'] <=> $b['cost']);
  $cats[$cat] = ['label' => $labels[$cat], 'options' => array_slice($opts, 0, 4), 'cheapest' => min(array_column($list, 'cost'))];
}
out(['city' => $city, 'iata' => $iata, 'available' => $avail, 'nights' => $nights, 'reduced_nights' => ($needHotel && $feasible && $usedNights < $nights) ? $usedNights : null, 'travellers' => $trav, 'feasible' => $feasible, 'shortfall' => $shortfall,
  'complete' => !$needOut && !$needRet && !$needHotel && $maxA === 0 && $maxR === 0, 'flights_unavailable' => !$flightsPossible,
  'package' => ['items' => $items, 'total' => $pkgCost, 'left' => $avail - $pkgCost], 'categories' => $cats]);
