<?php

namespace Database\Factories;

use App\Domain\Monitoring\Models\MonitoringCollector;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonitoringCollector> */
class MonitoringCollectorFactory extends Factory
{
    protected $model = MonitoringCollector::class;

    public function definition(): array
    {
        return [
            'collector_uuid' => fake()->uuid(),
            'name' => fake()->words(3, true),
            'status' => 'online',
            'last_seen_at' => now(),
            'config' => [],
            // A factory hub stands for a working hub, which an admin has approved (S&D Phase B).
            'approved_at' => now(),
        ];
    }

    /** A hub that has connected but no admin has approved yet. */
    public function waitingForApproval(): static
    {
        return $this->state(fn (): array => ['approved_at' => null, 'status' => 'pending']);
    }
}
