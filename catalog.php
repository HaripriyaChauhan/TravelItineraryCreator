<?php
// FAKE supplier catalogue (no output). Repeatable results for the same inputs. Swap these two functions for real APIs later. (Owner: Tilak)
require_once 'config.php';

function catalog_flights($from, $to, $date) {
  mt_srand(crc32("$from$to$date"));
  $airlines = [['Emirates', 'EK'], ['Qatar Airways', 'QR'], ['Turkish Airlines', 'TK'], ['Kenya Airways', 'KQ'], ['Ethiopian', 'ET']];
  $flights = [];
  for ($i = 0; $i < 6; $i++) {
    $al = $airlines[mt_rand(0, 4)]; $h = mt_rand(5, 21); $min = [0, 15, 30, 45][mt_rand(0, 3)]; $dur = mt_rand(180, 780);
    $num = $al[1] . mt_rand(100, 999); $stops = mt_rand(0, 10) > 6 ? 1 : 0; $price = kes(mt_rand(180, 900));
    $ref = "$num|$from|$to|$date"; $dep = $h * 60 + $min; $arr = ($dep + $dur) % 1440;
    $flights[] = ['airline' => $al[0], 'number' => $num, 'from' => $from, 'to' => $to, 'date' => $date,
      'depart' => sprintf('%02d:%02d', intdiv($dep, 60), $dep % 60), 'arrive' => sprintf('%02d:%02d', intdiv($arr, 60), $arr % 60),
      'duration' => $dur, 'stops' => $stops, 'price' => $price, 'unit_price' => $price, 'currency' => 'KES', 'unit' => 'per person',
      'ref' => $ref, 'quote' => quote_sign('flight', $ref, $price)];
  }
  return $flights;
}

function catalog_venues($type, $city) {
  mt_srand(crc32($type . strtolower($city)));
  $names = [
    'hotel' => ['Grand Hotel', 'Plaza Inn', 'Harbour View Suites', 'Central Boutique Stay', 'City Lodge'],
    'activity' => ['Old Town Walking Tour', 'Museum Visit', 'Sunset Cruise', 'Cooking Class', 'Day Trip Excursion'],
    'restaurant' => ['Local Taverna', 'Seafood Grill', 'Rooftop Kitchen', 'Street Food Market', 'Family Bistro'],
  ];
  $res = [];
  foreach ($names[$type] as $n) {
    $r = ['type' => $type, 'name' => "$n - " . ucfirst($city), 'rating' => round(mt_rand(35, 50) / 10, 1)];
    if ($type === 'hotel') { $r['price'] = kes(mt_rand(60, 300)); $r['detail'] = 'KES ' . number_format($r['price']) . ' per night'; $r['duration'] = 30; $r['unit'] = 'per night'; }
    else {
      $o = mt_rand(8, 12); $c = mt_rand(16, 23);
      $r += ['opens' => sprintf('%02d:00', $o), 'closes' => sprintf('%02d:00', $c), 'price' => kes(mt_rand(10, $type === 'activity' ? 90 : 45)), 'duration' => $type === 'activity' ? 120 : 60, 'unit' => 'per person'];
      $r['detail'] = "Open {$r['opens']}-{$r['closes']} · " . 'KES ' . number_format($r['price']) . ' per person';
    }
    $r['unit_price'] = $r['price']; $r['currency'] = 'KES'; $r['ref'] = $type . '|' . $r['name']; $r['quote'] = quote_sign($type, $r['ref'], $r['price']);
    $res[] = $r;
  }
  return $res;
}
