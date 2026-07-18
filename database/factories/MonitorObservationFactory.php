<?php

namespace Database\Factories;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonitorObservation> */
class MonitorObservationFactory extends Factory
{
    protected $model = MonitorObservation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'monitor_id' => Monitor::factory(),
            'source_key' => fake()->uuid(),
            'state' => MonitorState::Healthy,
            'latency_ms' => fake()->numberBetween(1, 100),
            'metrics' => [],
            'observed_at' => now(),
            'ingested_at' => now(),
        ];
    }
}
