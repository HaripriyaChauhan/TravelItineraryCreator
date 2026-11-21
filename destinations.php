<?php
// Destination guide data: places to roam, things to do, food, tips + airport code. Add a country by copying one block. (Owner: Paul / Tilak)
function destinations() {
  return [
 'Greece' => ['iata' => 'ATH', 'alias' => ['athens', 'santorini', 'mykonos', 'crete', 'corfu', 'rhodes'],
  'places' => [['Acropolis, Athens', 'Ancient citadel with the Parthenon - go early to beat the heat'], ['Plaka neighbourhood', 'Stroll cobbled lanes, shops and tavernas below the Acropolis'], ['Oia sunset, Santorini', 'Whitewashed village famous for caldera sunsets'], ['Delphi', 'Day trip to the ancient oracle site in the mountains']],
  'do' => [['Island ferry hopping', 'Explore the Cyclades by ferry'], ['Greek cooking class', 'Learn moussaka and tzatziki from locals'], ['Sunset catamaran sail', 'Swim and dine on board']],
  'food' => ['Souvlaki', 'Moussaka', 'Horiatiki (Greek salad)'], 'tip' => 'Book ferries ahead in summer and carry some cash for small tavernas.'],
 'Japan' => ['iata' => 'TYO', 'alias' => ['tokyo', 'kyoto', 'osaka', 'hokkaido', 'nara'],
  'places' => [['Senso-ji Temple, Tokyo', "Tokyo's oldest temple, in Asakusa"], ['Fushimi Inari Shrine, Kyoto', 'Thousands of red torii gates up the hillside'], ['Shibuya Crossing', "The world's busiest pedestrian crossing"], ['Nara Park', 'Friendly deer and the giant Todai-ji Buddha']],
  'do' => [['Tea ceremony', 'Traditional matcha ceremony in Kyoto'], ['Mt Fuji day trip', 'Views from Lake Kawaguchiko'], ['Sushi-making class', 'Learn from a local chef']],
  'food' => ['Ramen', 'Sushi', 'Takoyaki'], 'tip' => 'Get a Suica/IC card for trains, and carry some cash.'],
 'Kenya' => ['iata' => 'NBO', 'alias' => ['nairobi', 'mombasa', 'maasai mara', 'masai mara', 'nakuru', 'naivasha', 'diani', 'amboseli', 'watamu', 'lamu'],
  'places' => [['Maasai Mara National Reserve', 'Big-five safaris and the great migration (Jul-Oct)'], ['Nairobi National Park', 'Wildlife with a city skyline backdrop'], ['Diani Beach', 'White sand and turquoise water on the coast'], ["Hell's Gate, Naivasha", 'Hike or cycle between cliffs and geothermal vents']],
  'do' => [['Hot air balloon safari', 'Sunrise over the Mara plains'], ['Giraffe Centre & elephant orphanage', 'Feed giraffes and meet rescued baby elephants'], ['Lamu dhow sail', 'Traditional sailing around the old town islands']],
  'food' => ['Nyama choma', 'Ugali & sukuma wiki', 'Swahili pilau'], 'tip' => 'Book safari lodges early for the July-October migration season.'],
 'France' => ['iata' => 'CDG', 'alias' => ['paris', 'nice', 'lyon', 'marseille', 'bordeaux'],
  'places' => [['Eiffel Tower', 'Go at sunset and stay for the night lights'], ['Louvre Museum', 'Book a timed slot to skip the queue'], ['Montmartre & Sacre-Coeur', 'Artists square and city views'], ['Palace of Versailles', 'Half-day trip from Paris']],
  'do' => [['Seine river cruise', 'See the main landmarks from the water'], ['Pastry-making class', 'Bake croissants and macarons'], ['Wine & cheese tasting', 'Guided tasting with a sommelier']],
  'food' => ['Croissant', 'Coq au vin', 'Crepes'], 'tip' => 'Many museums are closed one day a week; check opening days.'],
 'Italy' => ['iata' => 'FCO', 'alias' => ['rome', 'venice', 'florence', 'milan', 'naples', 'amalfi'],
  'places' => [['Colosseum, Rome', 'Combine with the Roman Forum'], ["Venice canals & St Mark's", 'Walk the lanes early, before the crowds'], ['Uffizi Gallery, Florence', 'Renaissance masterpieces'], ['Amalfi Coast', 'Cliffside villages and sea views']],
  'do' => [['Pasta-making class', 'Roll fresh tagliatelle'], ['Gondola ride', 'Quiet canals of Venice'], ['Vatican guided tour', 'Sistine Chapel with a skip-the-line guide']],
  'food' => ['Carbonara', 'Neapolitan pizza', 'Gelato'], 'tip' => 'Validate regional train tickets before boarding to avoid fines.'],
 'USA' => ['iata' => 'JFK', 'alias' => ['new york', 'nyc', 'los angeles', 'san francisco', 'miami', 'chicago', 'las vegas', 'usa', 'united states'],
  'places' => [['Statue of Liberty', 'Ferry from Battery Park'], ['Central Park', 'Rowboats, walks and street musicians'], ['Times Square', 'Neon lights - best at night'], ['Brooklyn Bridge', 'Walk across for skyline views']],
  'do' => [['Broadway show', 'Book tickets in advance'], ['Top of the Rock views', 'Sunset skyline view'], ['Brooklyn food walking tour', 'Taste local favourites']],
  'food' => ['New York pizza slice', 'Bagel with lox', 'Cheesecake'], 'tip' => 'Tipping 18-20% in restaurants is expected.'],
 'Indonesia' => ['iata' => 'DPS', 'alias' => ['bali', 'ubud', 'jakarta', 'lombok', 'seminyak', 'indonesia'],
  'places' => [['Uluwatu Temple', 'Clifftop temple, sunset Kecak dance'], ['Tegallalang Rice Terraces, Ubud', 'Iconic green terraces'], ['Nusa Penida', 'Dramatic cliffs and Kelingking beach'], ['Sacred Monkey Forest', 'Forest sanctuary in Ubud']],
  'do' => [['Surf lesson', 'Beginner-friendly waves at Kuta'], ['Mount Batur sunrise trek', 'Pre-dawn hike to the crater'], ['Balinese cooking class', 'Market visit and cooking']],
  'food' => ['Nasi goreng', 'Satay', 'Babi guling'], 'tip' => 'Hire a driver for day trips; scooters need an international licence.'],
 'UAE' => ['iata' => 'DXB', 'alias' => ['dubai', 'abu dhabi', 'sharjah', 'uae', 'emirates'],
  'places' => [['Burj Khalifa', 'Book the sunset slot for the best views'], ['Dubai Mall & Fountain', 'Evening fountain shows'], ['Sheikh Zayed Grand Mosque', 'Abu Dhabi marble landmark'], ['Palm Jumeirah', 'Beaches and resorts on the palm']],
  'do' => [['Desert safari', 'Dune bashing and a camp dinner'], ['Dhow dinner cruise', 'Dubai Creek or Marina'], ['Gold & spice souk visit', 'Old Dubai markets']],
  'food' => ['Shawarma', 'Machboos', 'Luqaimat'], 'tip' => 'Dress modestly at mosques; summer is very hot, plan indoors at midday.'],
 'Thailand' => ['iata' => 'BKK', 'alias' => ['bangkok', 'phuket', 'chiang mai', 'krabi', 'pattaya', 'thailand'],
  'places' => [['Grand Palace, Bangkok', 'Royal complex and Emerald Buddha'], ['Phi Phi Islands', 'Boat trip to turquoise lagoons'], ['Doi Suthep, Chiang Mai', 'Hilltop temple with city views'], ['Wat Arun', 'Temple of Dawn on the river']],
  'do' => [['Thai cooking class', 'Market tour and pad thai'], ['Ethical elephant sanctuary visit', 'Observe and feed rescued elephants'], ['Longtail boat island hop', 'Beaches and snorkelling']],
  'food' => ['Pad thai', 'Tom yum', 'Mango sticky rice'], 'tip' => 'Cover shoulders and knees when visiting temples.'],
 'Spain' => ['iata' => 'MAD', 'alias' => ['madrid', 'barcelona', 'seville', 'valencia', 'granada', 'malaga', 'spain'],
  'places' => [['Sagrada Familia, Barcelona', 'Book tickets online ahead'], ['Alhambra, Granada', 'Moorish palace; sells out weeks ahead'], ['Prado Museum, Madrid', 'Velazquez and Goya masterpieces'], ['Park Guell', "Gaudi's mosaic hilltop park"]],
  'do' => [['Flamenco show', 'Authentic tablao in Seville'], ['Tapas crawl', 'Hop between small bars'], ['Toledo day trip', 'Medieval city from Madrid']],
  'food' => ['Paella', 'Jamon iberico', 'Churros with chocolate'], 'tip' => 'Dinner is late (9pm+); many shops close for a midday siesta.'],
];
}
// Returns [countryName, data] for a city/country text such as "Athens, Santorini", or null.
function dest_lookup($q) {
  $q = strtolower(trim($q));
  foreach (destinations() as $country => $d) {
    if (str_contains($q, strtolower($country))) return [$country, $d];
    foreach ($d['alias'] as $a) if (str_contains($q, $a)) return [$country, $d];
  }
  return null;
}
