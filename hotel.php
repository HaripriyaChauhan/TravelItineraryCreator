<?php
// FAKE hotel details API: rooms, amenities, address, policies. Repeatable per hotel+city. (Owner: Tilak)
require 'config.php';
require_login();
$name = trim($_GET['name'] ?? ''); $city = trim($_GET['city'] ?? '');
if (!$name) out(['error' => 'Hotel name required'], 400);
mt_srand(crc32(strtolower($name . $city)));
$stars = mt_rand(3, 5);
$base = isset($_GET['price']) && $_GET['price'] > 0 ? (int)$_GET['price'] : kes(50 + $stars * 30 + mt_rand(0, 40));
$pool = ['Free Wi-Fi', 'Breakfast included', 'Swimming pool', 'Airport shuttle', 'Gym', 'Spa', 'Air conditioning', 'Restaurant & bar', '24h reception', 'Parking', 'Pet friendly', 'Sea/city view'];
$am = ['Free Wi-Fi', '24h reception'];
while (count($am) < 6) { $x = $pool[mt_rand(0, 11)]; if (!in_array($x, $am)) $am[] = $x; }
$street = ['Main Street', 'Harbour Road', 'Old Town Square', 'Central Avenue', 'Garden Lane'][mt_rand(0, 4)];
$shortName = explode(' - ', $name)[0];
out(['hotel' => [
  'name' => $name, 'city' => $city, 'stars' => $stars, 'rating' => round(mt_rand(70, 96) / 10, 1), 'reviews' => mt_rand(120, 4200),
  'address' => mt_rand(1, 200) . " $street, " . ($city ?: 'City Centre'),
  'description' => "$shortName is a $stars-star hotel in the heart of " . ($city ?: 'the city') . ", close to the main sights, restaurants and public transport. Guests praise the friendly staff and comfortable rooms.",
  'check_in' => ['14:00', '15:00'][mt_rand(0, 1)], 'check_out' => ['11:00', '12:00'][mt_rand(0, 1)],
  'amenities' => $am,
  'rooms' => [['type' => 'Standard Double', 'price' => $base, 'sleeps' => 2], ['type' => 'Deluxe Room', 'price' => (int)(round($base * 1.4 / 50) * 50), 'sleeps' => 2], ['type' => 'Family Suite', 'price' => (int)(round($base * 1.9 / 50) * 50), 'sleeps' => 4]],
  'nearby' => ['Old town centre - ' . mt_rand(3, 15) . ' min walk', 'Public transport station - ' . mt_rand(2, 10) . ' min walk', 'Local market - ' . mt_rand(2, 12) . ' min walk', 'Airport - ' . mt_rand(8, 40) . ' km'],
  'policy' => ['Free cancellation up to 48 hours before check-in.', 'Free cancellation up to 24 hours before check-in.', 'Non-refundable rate; flexible rates available.'][mt_rand(0, 2)],
]]);
