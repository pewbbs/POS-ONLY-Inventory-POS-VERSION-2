<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductFamily> */
class ProductFamilyFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'name' => fake()->words(2, true), 'option_names' => ['size', 'color'],
            'defaults' => ['category' => 'Apparel', 'price' => 590, 'cost' => 210, 'wholesale_price' => null, 'reorder_level' => 10, 'color' => '#ddd9d0'],
            'creation_key' => (string) Str::uuid(), 'creation_hash' => hash('sha256', Str::random(32))];
    }
}
