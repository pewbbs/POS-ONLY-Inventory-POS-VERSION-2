<?php

namespace Database\Factories;

use App\Models\PayMongoWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayMongoWebhookEvent>
 */
class PayMongoWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_key' => 'evt_'.fake()->unique()->lexify('????????????????'),
            'payload_digest' => hash('sha256', fake()->uuid()),
            'session_id' => 'cs_'.fake()->unique()->lexify('????????????????'),
            'reference' => fake()->uuid(),
            'mode' => 'test',
            'status' => 'queued',
            'processed_at' => null,
            'failure_summary' => null,
        ];
    }
}
