<?php

namespace Database\Factories;

use App\Models\BillingInvoice;
use App\Models\BillingPaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingPaymentAttempt>
 */
class BillingPaymentAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_invoice_id' => BillingInvoice::factory(),
            'tenant_id' => fn (array $attributes): int => BillingInvoice::findOrFail($attributes['billing_invoice_id'])->tenant_id,
            'reference' => fn (): string => (string) Str::uuid(),
            'active_scope' => fn (array $attributes): string => 'invoice:'.$attributes['billing_invoice_id'],
            'status' => 'creating', 'session_id' => null, 'payment_id' => null,
            'amount_cents' => fn (array $attributes): int => (int) BillingInvoice::findOrFail($attributes['billing_invoice_id'])->amount_cents,
            'currency' => 'PHP', 'mode' => 'test', 'checkout_url' => null,
            'checkout_payload' => [], 'last_requested_at' => now(), 'confirmed_at' => null, 'failure_summary' => null,
        ];
    }
}
