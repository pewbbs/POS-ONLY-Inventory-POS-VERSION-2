<?php

namespace Database\Factories;

use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlatformAuditLog> */
class PlatformAuditLogFactory extends Factory
{
    public function definition(): array
    {
        return ['platform_admin_id' => PlatformAdmin::factory(), 'tenant_id' => Tenant::factory(),
            'action' => 'company.paused', 'reason' => 'Requested maintenance window',
            'metadata' => ['operations_paused' => true], 'created_at' => now()];
    }
}
