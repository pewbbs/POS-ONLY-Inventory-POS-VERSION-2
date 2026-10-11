<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PurchaseOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'number' => 'PO-'.Str::upper(Str::random(12)), 'status' => 'ordered', 'source' => 'manual',
            'items' => function (array $attributes): array {
                $product = Product::factory()->create(['tenant_id' => $attributes['tenant_id']]);

                return [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 5, 'received_quantity' => 0, 'unit_cost' => $product->cost_cents / 100, 'line_total' => 5 * $product->cost_cents / 100]];
            },
            'total_cents' => fn (array $attributes): int => (int) round(array_sum(array_column($attributes['items'], 'line_total')) * 100),
            'approved_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => 'draft', 'approved_at' => null]);
    }
}
