<?php
// PAYMENT SETTINGS (Owner: Tilak / Paul). Never commit real keys - use environment variables on a real server.
//
// mode:  'mock'     = demo mode, NO real money moves. M-Pesa / Airtel Money / card are simulated (default, works offline).
//        'sandbox'  = real Safaricom Daraja SANDBOX (test money) - needs the keys below and a public https callback URL.
//        'live'     = real M-Pesa. Only after Safaricom has approved your Paybill/Till and "Go Live" on developer.safaricom.co.ke.
return [
  'mode' => getenv('TIC_PAY_MODE') ?: 'mock',
  'mock_delay' => 6,                                  // seconds the simulated phone prompt takes
  'quote_secret' => getenv('TIC_QUOTE_SECRET') ?: 'CHANGE-ME-quote-secret-8f3a91c2d7',      // signs search prices so the browser cannot change them
  'callback_secret' => getenv('TIC_CALLBACK_SECRET') ?: 'CHANGE-ME-callback-secret-51be07a4', // protects the M-Pesa callback URL

  'mpesa' => [
    'consumer_key' => getenv('MPESA_KEY') ?: '',      // from developer.safaricom.co.ke -> My Apps
    'consumer_secret' => getenv('MPESA_SECRET') ?: '',
    'shortcode' => getenv('MPESA_SHORTCODE') ?: '174379',   // sandbox Paybill. Live: your Paybill or Till number
    'passkey' => getenv('MPESA_PASSKEY') ?: 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919', // public sandbox passkey
    'transaction_type' => 'CustomerPayBillOnline',    // Till number: 'CustomerBuyGoodsOnline'
    'party_b' => '',                                  // leave empty to use shortcode (Till: your till number)
    // Public HTTPS address of api/payments.php, e.g. from ngrok: https://abcd-1234.ngrok-free.app/travel-itinerary-creator/api/payments.php
    'callback_base' => getenv('MPESA_CALLBACK') ?: '',
  ],
];
