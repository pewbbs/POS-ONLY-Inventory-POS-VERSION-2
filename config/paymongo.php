<?php

return [
    'enabled' => env('PAYMONGO_ENABLED', false),
    'mode' => env('PAYMONGO_MODE', 'test'),
    'secret_key' => env('PAYMONGO_SECRET_KEY', ''),
    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET', ''),
    'payment_methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMONGO_PAYMENT_METHODS', 'card,gcash'))))),
    'webhook_tolerance_seconds' => 300,
];
