<?php

namespace Database\Factories;

use App\Models\LegalHold;
use Illuminate\Database\Eloquent\Factories\Factory;

class LegalHoldFactory extends Factory
{
    protected $model = LegalHold::class;

    public function definition(): array
    {
        return [
            'hold_reference' => 'LH-' . fake()->unique()->numerify('####'),
            'hold_type' => fake()->randomElement(['litigation', 'regulatory', 'investigation', 'audit']),
            'reason' => fake()->sentence(),
            'holdable_type' => 'App\\Models\\Client',
            'holdable_id' => 1,
            'imposed_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'status' => fake()->randomElement(['active', 'released']),
        ];
    }
}
