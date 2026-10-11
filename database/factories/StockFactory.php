<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stock> */
class StockFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(),
            'product_id' => fn (array $attributes): int => Product::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'quantity' => 10, 'reserved_quantity' => 0];
    }
}
