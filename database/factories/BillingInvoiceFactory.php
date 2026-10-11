<?php

namespace Database\Factories;

use App\Models\BillingInvoice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingInvoice>
 */
class BillingInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['subscription_id' => Subscription::factory(), 'tenant_id' => fn (array $attributes) => Subscription::findOrFail($attributes['subscription_id'])->tenant_id, 'number' => 'INV-'.Str::upper(Str::random(12)), 'plan' => 'grow', 'billing_cycle' => 'monthly', 'amount_cents' => 99900, 'currency' => 'PHP', 'status' => 'open', 'due_at' => now()];
    }
}
