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
        ];
    }
}
