<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\FleetIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetIncidentFactory extends Factory
{
    protected $model = FleetIncident::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'reported_by_user_id' => User::factory(),
            'driver_user_id' => User::factory(),
            'incident_type' => fake()->randomElement(['collision', 'damage', 'breakdown', 'theft', 'vandalism', 'other']),
            // FleetIncident severity vocab is minor/moderate/major/critical (model SEVERITIES);
            // mapped to the H&S low/medium/high/critical at cross-module boundaries.
            'severity' => fake()->randomElement(['minor', 'moderate', 'major', 'critical']),
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'location' => fake()->address(),
            'description' => fake()->paragraph(),
            'status' => 'open',
        ];
    }

    public function highSeverity(): static
    {
        return $this->state(fn () => ['severity' => 'major']);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }

    public function low(): static
    {
        return $this->state(fn () => ['severity' => 'minor']);
    }
}
