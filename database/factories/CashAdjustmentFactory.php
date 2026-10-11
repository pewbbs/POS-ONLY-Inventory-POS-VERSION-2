<?php

namespace Database\Factories;

use App\Models\CashAdjustment;
use App\Models\CashSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CashAdjustment> */
class CashAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_session_id' => CashSession::factory(),
            'tenant_id' => fn (array $attributes): int => CashSession::findOrFail($attributes['cash_session_id'])->tenant_id,
            'user_id' => fn (array $attributes): int => CashSession::findOrFail($attributes['cash_session_id'])->user_id,
            'direction' => 'in', 'amount_cents' => 10000, 'reason' => 'Additional change',
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'factory'),
        ];
    }
}
