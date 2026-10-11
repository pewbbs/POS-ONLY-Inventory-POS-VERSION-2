<?php

namespace Database\Factories;

use App\Models\CashSession;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CashSession> */
class CashSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => fn (array $attributes): int => User::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'status' => 'open', 'opening_key' => (string) Str::uuid(), 'opening_hash' => hash('sha256', 'factory'),
            'open_scope' => fn (array $attributes): string => $attributes['tenant_id'].':'.$attributes['user_id'].':'.$attributes['location_id'],
            'opening_float_cents' => 10000, 'cash_sales_cents' => 0, 'cash_in_cents' => 0, 'cash_out_cents' => 0,
            'opened_at' => now(),
        ];
    }
}
