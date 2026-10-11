<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SaleReturn> */
class SaleReturnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'tenant_id' => fn (array $attributes): int => Sale::findOrFail($attributes['sale_id'])->tenant_id,
            'user_id' => fn (array $attributes): int => Sale::findOrFail($attributes['sale_id'])->user_id,
            'number' => 'RT-'.Str::upper(Str::random(12)),
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'factory'),
            'reason' => 'Customer return', 'items' => [],
            'subtotal_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'total_cents' => 10000,
        ];
    }
}
