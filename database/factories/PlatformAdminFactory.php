<?php

namespace Database\Factories;

use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlatformAdmin> */
class PlatformAdminFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(),
            'password' => 'DeveloperPassword123!', 'is_active' => true,
            'setup_token_hash' => null, 'setup_expires_at' => null];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['password' => null, 'is_active' => false]);
    }
}
