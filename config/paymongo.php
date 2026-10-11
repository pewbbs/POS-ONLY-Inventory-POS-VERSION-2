<?php

return [
    'enabled' => env('PAYMONGO_ENABLED', false),
    'mode' => env('PAYMONGO_MODE', 'test'),
    'secret_key' => env('PAYMONGO_SECRET_KEY', ''),
    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET', ''),
    'payment_methods' => ['card', 'gcash'],
    'webhook_tolerance_seconds' => 300,
];
