<?php

namespace Database\Factories;

use App\Models\ChannelOrder;
use App\Models\CommerceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChannelOrder> */
class ChannelOrderFactory extends Factory
{
    public function definition(): array
    {
        return ['commerce_channel_id' => CommerceChannel::factory(),
            'tenant_id' => fn (array $attributes): int => CommerceChannel::findOrFail($attributes['commerce_channel_id'])->tenant_id,
            'location_id' => fn (array $attributes): int => CommerceChannel::findOrFail($attributes['commerce_channel_id'])->location_id,
            'user_id' => fn (array $attributes): int => CommerceChannel::findOrFail($attributes['commerce_channel_id'])->user_id,
            'external_reference' => 'SIM-'.Str::upper(Str::random(12)), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(32)),
            'status' => 'pending', 'items' => [], 'subtotal_cents' => 0, 'discount_cents' => 0, 'tax_percent' => 0, 'tax_cents' => 0, 'total_cents' => 0];
    }
}
