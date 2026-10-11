<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use App\Models\ProductVariantBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductVariantBatch> */
class ProductVariantBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['product_family_id' => ProductFamily::factory(),
            'tenant_id' => fn (array $attributes): int => ProductFamily::findOrFail($attributes['product_family_id'])->tenant_id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'manager'])->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(32)), 'product_ids' => []];
    }
}
