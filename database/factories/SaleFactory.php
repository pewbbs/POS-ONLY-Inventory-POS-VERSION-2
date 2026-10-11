<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(), 'location_id' => fn (array $attributes): int => Location::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'number' => 'SR-'.Str::upper(Str::random(12)), 'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'factory'),
            'items' => [], 'payments' => [['method' => 'cash', 'amount_cents' => 10000]],
            'subtotal_cents' => 10000, 'discount_percent' => 0, 'discount_cents' => 0,
            'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 10000,
            'paid_cents' => 10000, 'change_cents' => 0,
            'price_tier' => 'retail',
            'returned_cents' => fn (array $attributes): int => $attributes['status'] === 'returned' ? $attributes['total_cents'] : 0,
        ];
    }
}
