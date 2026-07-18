<?php

namespace Database\Factories;

use App\Domain\Monitoring\Models\MonitoringProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonitoringProfile> */
class MonitoringProfileFactory extends Factory
{
    protected $model = MonitoringProfile::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'name' => fake()->unique()->words(3, true),
            'interval_seconds' => 60,
            'failure_confirmations' => 3,
            'recovery_confirmations' => 2,
            'stale_after_seconds' => 300,
            'is_active' => true,
        ];
    }
}
