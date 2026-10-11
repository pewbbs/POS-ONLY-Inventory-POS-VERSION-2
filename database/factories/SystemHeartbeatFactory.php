<?php

namespace Database\Factories;

use App\Models\SystemHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemHeartbeat>
 */
class SystemHeartbeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'component' => 'scheduler',
            'connection' => null,
            'queue' => null,
            'scope_key' => hash('sha256', json_encode(['scheduler', null, null], JSON_THROW_ON_ERROR)),
            'last_seen_at' => now(),
        ];
    }

    public function worker(string $connection = 'database', string $queue = 'default'): static
    {
        return $this->state(fn (array $attributes): array => [
            'component' => 'worker', 'connection' => $connection, 'queue' => $queue,
            'scope_key' => hash('sha256', json_encode(['worker', $connection, $queue], JSON_THROW_ON_ERROR)),
        ]);
    }
}
