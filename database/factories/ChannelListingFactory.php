<?php

namespace Database\Factories;

use App\Models\ChannelListing;
use App\Models\CommerceChannel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChannelListing> */
class ChannelListingFactory extends Factory
{
    public function definition(): array
    {
        return ['commerce_channel_id' => CommerceChannel::factory(),
            'tenant_id' => fn (array $attributes): int => CommerceChannel::findOrFail($attributes['commerce_channel_id'])->tenant_id,
            'product_id' => fn (array $attributes): int => Product::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'sku' => fn (array $attributes): string => Product::findOrFail($attributes['product_id'])->sku,
            'name' => fn (array $attributes): string => Product::findOrFail($attributes['product_id'])->name,
            'price_cents' => 10000, 'published_quantity' => 0];
    }
}
