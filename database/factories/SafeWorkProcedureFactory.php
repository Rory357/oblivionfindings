<?php

namespace Database\Factories;

use App\Models\SafeWorkProcedure;
use Illuminate\Database\Eloquent\Factories\Factory;

class SafeWorkProcedureFactory extends Factory
{
    protected $model = SafeWorkProcedure::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'reference_number' => 'SWP-' . fake()->unique()->numerify('###'),
            'category' => fake()->randomElement(['manual_handling', 'chemical', 'electrical', 'working_at_height', 'confined_space']),
            'status' => 'active',
        ];
    }
}
