<?php

return [
    'demo_enabled' => env('DEMO_ENABLED', false),
    'worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 60),
];
