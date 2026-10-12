<?php

return [
    'token_lifetime_days' => 30,
    'max_devices' => 10,
    'abilities' => [
        'cashier' => ['account:read', 'tokens:manage', 'catalog:read', 'customers:read', 'customers:write', 'sales:read', 'sales:write', 'cash:read', 'cash:write', 'reports:read'],
        'manager' => ['account:read', 'tokens:manage', 'catalog:read', 'catalog:write', 'customers:read', 'customers:write', 'suppliers:read', 'suppliers:write', 'sales:read', 'sales:write', 'returns:write', 'inventory:write', 'purchases:read', 'purchases:write', 'cash:read', 'cash:write', 'reports:read', 'financial:read'],
        'admin' => ['account:read', 'tokens:manage', 'catalog:read', 'catalog:write', 'customers:read', 'customers:write', 'suppliers:read', 'suppliers:write', 'sales:read', 'sales:write', 'returns:write', 'inventory:write', 'purchases:read', 'purchases:write', 'cash:read', 'cash:write', 'reports:read', 'financial:read'],
    ],
];
