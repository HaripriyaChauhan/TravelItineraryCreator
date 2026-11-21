-- Travel Itinerary Creator - MySQL schema (import in phpMyAdmin if you prefer manual setup).
-- NOTE: the PHP app creates all of this automatically on first run, so importing is optional.
CREATE DATABASE IF NOT EXISTS travel_itinerary CHARACTER SET utf8mb4;
USE travel_itinerary;

CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(16) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,          -- bcrypt via password_hash()
  currency VARCHAR(5) NOT NULL DEFAULT 'KES',
  phone VARCHAR(20) NULL,                       -- 2547XXXXXXXX, used for M-Pesa / Airtel Money
  reset_code VARCHAR(10) NULL,
  reset_exp INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trips (
  id VARCHAR(16) PRIMARY KEY,
  user_id VARCHAR(16) NOT NULL,
  name VARCHAR(160) NOT NULL,
  destination VARCHAR(200),
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  travellers INT NOT NULL DEFAULT 1,
  budget DOUBLE NOT NULL DEFAULT 0,
  image VARCHAR(500) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activities (
  id VARCHAR(16) PRIMARY KEY, trip_id VARCHAR(16) NOT NULL, type VARCHAR(20), title VARCHAR(200) NOT NULL,
  act_date DATE NOT NULL, act_time VARCHAR(5), duration INT DEFAULT 60, opens VARCHAR(5) NULL, closes VARCHAR(5) NULL,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
  id VARCHAR(16) PRIMARY KEY, trip_id VARCHAR(16) NOT NULL, type VARCHAR(20), title VARCHAR(200) NOT NULL,
  book_date DATE NOT NULL, book_time VARCHAR(5), duration INT DEFAULT 60, detail VARCHAR(255) NULL, status VARCHAR(20) DEFAULT 'Confirmed',
  opens VARCHAR(5) NULL, closes VARCHAR(5) NULL,
  ref VARCHAR(190) NULL,                        -- supplier reference the signed quote was issued for
  unit_price DOUBLE NOT NULL DEFAULT 0,         -- KES per person / per night, from the signed quote
  qty INT NOT NULL DEFAULT 1,                   -- travellers or nights
  price DOUBLE NOT NULL DEFAULT 0,              -- server-calculated: unit_price x qty (0 = booked elsewhere)
  quote VARCHAR(64) NULL,                       -- HMAC signature proving the price came from our search API
  payment_id VARCHAR(16) NULL,                  -- set when status becomes 'Paid'
  seats VARCHAR(80) NULL,                       -- flights only: chosen seats, e.g. '1A,1B' (validated + priced by the server)
  seat_fee DOUBLE NOT NULL DEFAULT 0,           -- sum of the seat fees (included in price)
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- bookings.status: 'Reserved' (in cart, awaiting payment) | 'Paid' (locked) | 'Confirmed' (booked elsewhere, no payment here)

CREATE TABLE IF NOT EXISTS expenses (
  id VARCHAR(16) PRIMARY KEY, trip_id VARCHAR(16) NOT NULL, cat VARCHAR(30), title VARCHAR(200) NOT NULL,
  amount DOUBLE NOT NULL DEFAULT 0, exp_date DATE NOT NULL,
  FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Financial records: kept even if a trip is removed (trips with paid bookings cannot be deleted).
CREATE TABLE IF NOT EXISTS payments (
  id VARCHAR(16) PRIMARY KEY, user_id VARCHAR(16) NOT NULL, trip_id VARCHAR(16) NOT NULL, trip_name VARCHAR(160),
  method VARCHAR(12) NOT NULL,                  -- mpesa | airtel | card
  phone VARCHAR(24) NULL,                       -- 2547XXXXXXXX, or '**** 1234' for cards (card numbers are never stored)
  amount DOUBLE NOT NULL, currency VARCHAR(5) NOT NULL DEFAULT 'KES',
  status VARCHAR(12) NOT NULL DEFAULT 'pending', -- pending | success | failed | cancelled | expired
  provider_ref VARCHAR(100) NULL,               -- Safaricom CheckoutRequestID
  merchant_ref VARCHAR(100) NULL,               -- Safaricom MerchantRequestID
  receipt VARCHAR(40) NULL,                     -- M-Pesa receipt code, e.g. SGH7K2L9QX
  result_desc VARCHAR(255) NULL,
  items TEXT NOT NULL,                          -- JSON snapshot of what was paid for
  created_at INT NOT NULL, paid_at INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
