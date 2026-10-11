<?php

namespace Database\Factories;

use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialIdentity>
 */
class SocialIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'subject_hash' => hash('sha256', fake()->uuid()),
            'email' => fake()->safeEmail(),
        ];
    }
}
