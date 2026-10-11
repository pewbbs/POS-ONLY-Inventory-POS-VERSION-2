<?php

return [
    'payment_provider' => env('BILLING_PAYMENT_PROVIDER', 'simulation'),
    'simulations_enabled' => env('BILLING_SIMULATIONS_ENABLED', true),
    'trial_days' => 14,
    'grace_days' => 7,
    'plans' => [
        'starter' => ['name' => 'Starter', 'monthly_cents' => 49900, 'annual_cents' => 479040, 'locations' => 1, 'team_members' => 2],
        'grow' => ['name' => 'Grow', 'monthly_cents' => 99900, 'annual_cents' => 959040, 'locations' => 3, 'team_members' => 10],
        'scale' => ['name' => 'Scale', 'monthly_cents' => 199900, 'annual_cents' => 1919040, 'locations' => 10, 'team_members' => null],
    ],
];
