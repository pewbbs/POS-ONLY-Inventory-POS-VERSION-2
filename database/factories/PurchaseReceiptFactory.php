<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PurchaseReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'tenant_id' => fn (array $attributes): int => PurchaseOrder::findOrFail($attributes['purchase_order_id'])->tenant_id,
            'user_id' => fn (array $attributes): int => PurchaseOrder::findOrFail($attributes['purchase_order_id'])->user_id,
            'number' => 'GR-'.Str::upper(Str::random(12)), 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'factory'), 'items' => [], 'note' => null,
        ];
    }
}
