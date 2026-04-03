<?php

namespace Database\Factories;

use App\Models\CompetencyFramework;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompetencyFrameworkFactory extends Factory
{
    protected $model = CompetencyFramework::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'active' => true,
        ];
    }
}
