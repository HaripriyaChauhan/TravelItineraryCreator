<?php
// Seat map for one flight: row types/prices + seats already taken. (Owner: Haripriya / Tilak)
require 'config.php';
require_login();
$ref = (string)($_GET['ref'] ?? '');
if (!preg_match('/^[A-Z0-9]{2,3}\d{2,4}\|[A-Z]{3}\|[A-Z]{3}\|\d{4}-\d{2}-\d{2}$/', $ref)) out(['error' => 'Seat selection is only available for flights'], 400);
$rows = [];
for ($n = 1; $n <= SEAT_ROWS; $n++) { [$t, $p] = seat_row_info($n); $rows[] = ['n' => $n, 't' => $t, 'p' => $p]; }
out(['ref' => $ref, 'letters' => SEAT_LETTERS, 'rows' => $rows, 'taken' => seats_taken(pdo(), $ref, (string)($_GET['exclude'] ?? ''))]);
