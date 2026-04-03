<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkplaceInjury;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkplaceInjuryFactory extends Factory
{
    protected $model = WorkplaceInjury::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'injury_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'injury_type' => fake()->randomElement(['strain', 'laceration', 'bruise', 'burn', 'fracture', 'slip_fall']),
            'severity' => fake()->randomElement(['minor', 'moderate', 'serious', 'critical']),
            'description' => fake()->paragraph(),
            'body_part_affected' => fake()->randomElement(['back', 'hand', 'arm', 'leg', 'head', 'shoulder']),
            'immediate_treatment' => fake()->sentence(),
        ];
    }
}
