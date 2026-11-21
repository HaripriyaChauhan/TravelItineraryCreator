<?php
// FAKE flights API - repeatable mock results (see catalog.php). Swap for a real API (Amadeus etc.) later. (Owner: Tilak)
require 'catalog.php';
require_login();
$from = strtoupper(substr($_GET['from'] ?? 'NBO', 0, 3));
$to = strtoupper(substr($_GET['to'] ?? 'ATH', 0, 3));
$date = $_GET['date'] ?? date('Y-m-d');
if ($from === $to) out(['error' => 'Origin and destination must differ'], 400);
$flights = catalog_flights($from, $to, $date);
usleep(300000); // simulate network delay
out(['flights' => $flights]);
