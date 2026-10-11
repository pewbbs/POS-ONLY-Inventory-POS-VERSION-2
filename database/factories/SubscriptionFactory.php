<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'plan' => 'grow', 'billing_cycle' => 'monthly', 'status' => 'trialing', 'trial_ends_at' => now()->addDays(14), 'cancel_at_period_end' => false];
    }
}
