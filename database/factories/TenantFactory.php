<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'is_demo' => false, 'settings' => Tenant::defaultSettings()];
    }
}
