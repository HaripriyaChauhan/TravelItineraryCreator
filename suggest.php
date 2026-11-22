<?php
// FAKE "smart suggestions" data: places to roam, things to do, food and tips per country. (Owner: Paul / Tilak)
// Add a country by copying one block. 'alias' = lowercase city/region names that map to that country.
require 'config.php';
require_login();
$q = strtolower(trim($_GET['destination'] ?? ''));
if (!$q) out(['error' => 'Trip has no destination'], 400);
require 'destinations.php';
$D = destinations();
$hit = null;
foreach ($D as $country => $d) {
  if (str_contains($q, strtolower($country))) { $hit = $country; break; }
  foreach ($d['alias'] as $a) if (str_contains($q, $a)) { $hit = $country; break 2; }
}
if ($hit) { $d = $D[$hit]; out(['country' => $hit, 'matched' => true, 'iata' => $d['iata'], 'places' => $d['places'], 'do' => $d['do'], 'food' => $d['food'], 'tip' => $d['tip']]); }
$city = ucwords(trim(explode(',', $q)[0]));
out(['country' => $city, 'matched' => false, 'iata' => '',
  'places' => [["Old town / city centre of $city", 'Wander the historic centre on foot'], ["Main museum in $city", 'Great for learning local history'], ["Best viewpoint in $city", 'Go at sunset for photos']],
  'do' => [['Free walking tour', 'Meet a local guide and get orientation'], ['Local food tour', 'Taste regional specialities'], ['Day trip to a nearby town', 'Ask your hotel for ideas']],
  'food' => ['Ask locals for their favourite regional dish', 'Visit a local market'], 'tip' => 'We have no curated guide for this destination yet - add it in api/suggest.php.']);
