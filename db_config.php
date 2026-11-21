<?php
// DATABASE SETTINGS - edit these to match your machine.
// driver: 'auto'   = try MySQL first, fall back to SQLite file if MySQL isn't running (default, zero-setup)
//         'mysql'  = MySQL/MariaDB only (XAMPP / WAMP / MAMP: user "root", empty password)
//         'sqlite' = single file data/travel.sqlite (needs the pdo_sqlite PHP extension)
// The database and all tables are created automatically on first run.
return [
  'driver' => 'auto',
  'host' => '127.0.0.1',
  'port' => 3306,
  'name' => 'travel_itinerary',
  'user' => 'root',
  'pass' => '',
  'sqlite_file' => __DIR__ . '/../data/travel.sqlite',
];
