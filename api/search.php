<?php
// FAKE accommodation / activity / restaurant search. Repeatable mock data (see catalog.php). (Owner: Tilak)
require 'catalog.php';
require_login();
$type = $_GET['type'] ?? 'hotel';
$city = trim($_GET['city'] ?? '');
if (!$city) out(['error' => 'Enter a city'], 400);
if (!in_array($type, ['hotel', 'activity', 'restaurant'])) out(['error' => 'Bad type'], 400);
$res = catalog_venues($type, $city);
usleep(250000);
out(['results' => $res]);
