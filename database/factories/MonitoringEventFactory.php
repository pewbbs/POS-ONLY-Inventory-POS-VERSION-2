<?php

namespace Database\Factories;

use App\Models\MonitoringEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringEvent>
 */
class MonitoringEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => 'application_error',
            'status' => 'failed',
            'summary' => 'An application request failed.',
            'error_class' => 'RuntimeException',
            'error_code' => 0,
            'pending_scope' => null,
            'completed_at' => null,
            'acknowledged_at' => null,
        ];
    }

    public function pendingProbe(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => 'queue_probe', 'status' => 'pending', 'summary' => 'A diagnostic queue probe is waiting to run.',
            'error_class' => null, 'error_code' => null, 'pending_scope' => 'probe:'.fake()->uuid(),
        ]);
    }
}
