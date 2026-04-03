<?php

namespace Database\Factories;

use App\Models\DataBreachLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class DataBreachLogFactory extends Factory
{
    protected $model = DataBreachLog::class;

    public function definition(): array
    {
        return [
            'breach_reference' => 'DBR-' . fake()->unique()->numerify('####'),
            'discovered_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'nature_of_breach' => fake()->randomElement(['unauthorised_access', 'data_loss', 'disclosure', 'alteration']),
            'likely_consequences' => fake()->paragraph(),
            'measures_taken' => fake()->paragraph(),
            'status' => fake()->randomElement(['discovered', 'under_investigation', 'contained', 'notified', 'resolved']),
        ];
    }
}
