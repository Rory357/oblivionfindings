<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\FleetShiftHandover;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetShiftHandoverFactory extends Factory
{
    protected $model = FleetShiftHandover::class;

    public function definition(): array
    {
        return [
            'organisation_id' => 1,
            'asset_id' => Asset::factory(),
            'outgoing_user_id' => User::factory(),
            'exterior_condition' => fake()->randomElement(['good', 'fair', 'poor', 'damaged']),
            'interior_condition' => fake()->randomElement(['clean', 'acceptable', 'needs_cleaning', 'damaged']),
            'handed_over_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'fuel_level' => fake()->randomElement(['full', 'three_quarter', 'half', 'quarter', 'empty']),
            'odometer_reading' => fake()->numberBetween(10000, 200000),
        ];
    }
}
