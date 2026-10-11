<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'name' => fake()->words(2, true), 'sku' => fake()->unique()->bothify('SKU-####'), 'category' => 'General', 'price_cents' => 45000, 'cost_cents' => 21000, 'reorder_level' => 10, 'color' => '#d8bba0'];
    }
}
