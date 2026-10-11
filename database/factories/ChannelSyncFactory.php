<?php

namespace Database\Factories;

use App\Models\ChannelSync;
use App\Models\CommerceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChannelSync> */
class ChannelSyncFactory extends Factory
{
    public function definition(): array
    {
        return ['commerce_channel_id' => CommerceChannel::factory(),
            'tenant_id' => fn (array $attributes): int => CommerceChannel::findOrFail($attributes['commerce_channel_id'])->tenant_id,
            'user_id' => fn (array $attributes): int => CommerceChannel::findOrFail($attributes['commerce_channel_id'])->user_id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', Str::random(32)),
            'status' => 'succeeded', 'message' => 'Local inventory snapshot refreshed.', 'listing_count' => 0];
    }
}
