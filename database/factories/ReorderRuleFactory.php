<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReorderRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => fn (array $attributes): int => Product::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'admin'])->id,
            'target_quantity' => 20, 'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
