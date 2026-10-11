<?php

namespace Database\Factories;

use App\Models\CommerceChannel;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommerceChannel> */
class CommerceChannelFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'location_id' => fn (array $attributes): int => Location::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['tenant_id' => $attributes['tenant_id'], 'role' => 'manager'])->id,
            'name' => fake()->company().' storefront', 'kind' => 'web_store', 'enabled' => true, 'simulate_failure' => false,
            'pending_sync' => true, 'sync_state' => 'stale', 'creation_key' => (string) Str::uuid(), 'creation_hash' => hash('sha256', Str::random(32))];
    }
}
