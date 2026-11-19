# Travel Itinerary Creator

Plan trips, manage bookings, track budget, detect schedule conflicts.
Stack: **HTML + CSS + vanilla JS** frontend · **PHP (PDO)** backend · **MySQL** database (automatic SQLite fallback).

## Run it
**Option A - XAMPP (MySQL):**
1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. Copy this folder into `C:\xampp\htdocs\` and open http://localhost/travel-itinerary-creator/
3. That's it - the database `travel_itinerary` and all tables are created automatically on first load.
   (Optional: import `database/schema.sql` in phpMyAdmin to create them by hand. Check the tables at http://localhost/phpmyadmin)

**Option B - no XAMPP:** install PHP, open a terminal in this folder and run `php -S localhost:8000`, then open http://localhost:8000.
If MySQL isn't running, the app automatically stores everything in `data/travel.sqlite` instead. Settings page shows which database is in use.

**Database settings** (host/user/password/driver) are in `api/db_config.php`. Default is MySQL user `root`, empty password (XAMPP default).
Set `'driver' => 'mysql'` to force MySQL, or `'sqlite'` to force the file database.

Then: register -> Home -> **Load sample trip** (includes a deliberate schedule conflict to demo the conflict engine).

## Payments (Kenya): M-Pesa, Airtel Money, cards
**The customer flow** (modelled on real booking apps): search -> *Add to trip* (item is **Reserved** and appears in the cart bar) -> **Review** (full trip summary, choose what to pay now) -> **Pay** -> waiting screen -> **Confirmation + receipt** -> bookings become **Paid**, an expense is logged automatically, budget updates.
Also: *Payments* page (history + receipts), *Summary* tab per trip (printable / save as PDF), phone number remembered in Settings.

| Mode (`api/payment_config.php` -> `mode`) | What happens |
|---|---|
| `mock` (default) | Demo: **no real money**. M-Pesa/Airtel/card are simulated. Phone ending `0000` = cancelled, `1111` = insufficient funds, `2222` = wrong PIN, anything else succeeds after ~6 s. Card `4111 1111 1111 1111` succeeds, card ending `0002` is declined |
| `sandbox` | Real Safaricom **Daraja sandbox** STK Push (test money). M-Pesa only |
| `live` | Real M-Pesa after Safaricom approval |

**Set up the Daraja sandbox (M-Pesa STK Push)**
1. Create an account at https://developer.safaricom.co.ke -> *My Apps* -> create an app with *Lipa na M-Pesa Sandbox* enabled -> copy the **Consumer Key** and **Consumer Secret**.
2. Safaricom must reach your callback over public **HTTPS**: run `ngrok http 80` (XAMPP) and copy the https address.
3. Put these in `api/payment_config.php` (or as environment variables `MPESA_KEY`, `MPESA_SECRET`, `MPESA_CALLBACK`): key, secret, and `callback_base` = `https://YOUR-NGROK-ADDRESS/travel-itinerary-creator/api/payments.php`. Set `mode` to `sandbox`.
4. Pay using the test phone number shown in the Daraja simulator. Without a callback the app still checks the payment by *querying* Safaricom every 3 s, so it works even if the callback is blocked.

**Go-live checklist (before real customers)**
- [ ] Safaricom *Go Live*: approved Paybill / Till, live Consumer Key/Secret, passkey; `mode = live`, `transaction_type` = `CustomerPayBillOnline` (Paybill) or `CustomerBuyGoodsOnline` (Till, also set `party_b`)
- [ ] Hosting on **HTTPS** with a real domain; keys and the two secrets (`quote_secret`, `callback_secret`) in environment variables, not in files; MySQL (not SQLite), daily backups
- [ ] Replace the fake flight/hotel APIs with real suppliers (e.g. Amadeus). **Re-fetch the price from the supplier at checkout** instead of trusting the earlier search quote
- [ ] Airtel Money (Airtel Africa API) and cards via a hosted checkout such as Pesapal or Flutterwave. Today these are demo-only and disabled outside `mock` mode
- [ ] Refund / cancellation flow (paid bookings are locked in this version) and a support contact
- [ ] Legal and tax, check with a Kenyan advisor: Data Protection Act 2019 (privacy policy, ODPC registration), terms and refund policy, VAT / KRA eTIMS invoicing
- [ ] Confirm current M-Pesa limits with Safaricom (`MPESA_MAX_TX` = KES 250,000 per transaction in `api/config.php`)

**Security model (server-side, the browser is never trusted)**
- Prices come from the search API as **signed quotes** (HMAC). The server recomputes `price = unit_price x qty` and treats anything without a valid quote as a free "booked elsewhere" record. Tampering with prices from the browser does not work (tested).
- The amount charged is calculated from the **database**, never taken from the request. Paid bookings and payment-generated expenses are **locked**; a trip with paid bookings cannot be deleted.
- One payment at a time per trip; payment finalisation is idempotent (a callback + a status check can never double-record).
- Callback URL is protected by a secret and the paid amount must match; card numbers are validated (Luhn, expiry) and **never stored**; every payment endpoint is login-protected and users only see their own payments.

## What changed in this version (restructure)
| Module | Change |
|---|---|
| Bookings | New lifecycle: `Reserved` (cart) -> `Paid` (locked) or `Confirmed` (booked elsewhere). Search results carry signed prices; quantity = travellers or nights |
| Budget | Now shows *Paid*, *Reserved (unpaid)* and *Left*; auto-logs an expense per paid category; conflict engine counts reserved money |
| Money | Everything in **KES** (currency setting replaced by an M-Pesa phone number in Settings) |
| API | `flights.php`, `search.php`, `hotel.php` return KES + quotes; new `payments.php`; `trips.php` enforces the money rules |
| Frontend | New `js/checkout.js` (cart bar, review, payment, receipt, history, summary); sample trip uses real quotes |
| Database | New `payments` table, new booking/user columns; **existing databases upgrade automatically** (tested) |

## Database design (6 tables)
```
users 1───< trips 1───< activities
      │           1───< bookings   (price, status Reserved/Paid/Confirmed, payment_id)
      │           1───< expenses   (ids starting "pay_" are created by payments)
      └───< payments  (items snapshot, M-Pesa receipt; kept even if a trip is removed)
```
- `users`: id, name, email (unique), password_hash (bcrypt), currency, reset_code, reset_exp, created_at
- `trips`: id, user_id, name, destination, start_date, end_date, travellers, budget, image
- `activities`: id, trip_id, type, title, act_date, act_time, duration, opens, closes
- `bookings`: id, trip_id, type (flight/hotel/activity/restaurant), title, book_date, book_time, duration, detail, status, opens, closes
- `expenses`: id, trip_id, cat, title, amount, exp_date
- `payments`: id, user_id, trip_id, trip_name, method, phone, amount, status, provider_ref, receipt, items, created_at, paid_at

Security: passwords are hashed with `password_hash()`; every query uses PDO prepared statements (no SQL injection); users can only read/change their own trips; output is HTML-escaped (no XSS).

## File map
| File | Purpose | Workplan owner |
|---|---|---|
| `api/config.php`, `api/db_config.php`, `database/schema.sql` | DB connection + schema | Tilak |
| `api/auth.php`, login UI in `index.html` | Register / login / reset / profile | Krisha |
| `api/trips.php` | Trip CRUD (with activities, bookings, expenses) | Haripriya / Tilak |
| `api/payments.php`, `api/payment_config.php` | M-Pesa Daraja STK Push, demo Airtel/card, receipts, history | Tilak / Paul |
| `js/checkout.js` | Cart bar, review/summary, payment screens, receipts | Paul / Kashish |
| `api/flights.php`, `api/search.php`, `api/hotel.php` | Fake flights / hotel+activity+restaurant search / hotel details APIs | Tilak |
| `api/suggest.php` | Country guide data: places to roam, things to do, food, tips (add countries here) | Paul / Tilak |
| `js/api.js` | All fetch calls | Tilak |
| `js/conflicts.js` | Conflict engine: overlaps, opening hours, budget, gaps, suggested fixes | Paul |
| `js/views.js` | HTML for every screen | Haripriya (itinerary), Kashish (budget), Krisha (home/settings) |
| `js/app.js` | Navigation, forms, drag-and-drop, auth screen | Kashish |
| `tests/` | QA | Yogi |

## Features
Register/login/logout/forgot password · dashboard with reminders + next trip · trips CRUD with optional cover image URL · day-by-day itinerary with drag-and-drop ·
edit/delete activities, bookings, expenses · flight + hotel + activity + restaurant search (fake APIs) that also log expenses ·
hotel details popup (rooms, amenities, address, policy) · destination-aware Explore tab + Smart Suggestions (places, activities, food, tips for Greece, Japan, Kenya, France, Italy, USA, Indonesia, UAE, Thailand, Spain; "+ Plan" adds to itinerary) · suggested bookings (missing flight / return flight / hotel / restaurants / tours) that jump to a pre-filled search ·
smart conflict engine with one-click "Apply fix" · budget with pie chart and progress bar · reminders + browser notifications · settings (name, currency, password).

## Testing
- `node tests/run-tests.js` - frontend/conflict-engine tests (needs Node.js)
- `sh tests/api-test.sh` - backend + database tests (needs PHP + curl): accounts, trips, APIs, plus `tests/pay-test.php` = 36 payment/security tests (signed prices, tampering, limits, cancel/insufficient funds, idempotency, privacy)
- `tests/QA_CHECKLIST.md` - manual checklist + bug report table

## Deployment (free PHP + MySQL host, e.g. InfinityFree)
1. Create a MySQL database in the host's control panel and note host/name/user/password.
2. Put those in `api/db_config.php` (`'driver' => 'mysql'`). Upload everything to `htdocs/`.
3. Open your site URL, register, done. (`data/.htaccess` blocks direct downloads on Apache.)

## Known limitations / future work
- Password reset shows the code on screen (demo) instead of emailing it - add PHPMailer for production.
- Flights/hotels/etc. are fake data - swap `api/flights.php` for a real API (Amadeus) later.
