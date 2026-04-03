<?php

namespace Database\Factories;

use App\Models\ControlRoom\Playbook;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlaybookFactory extends Factory
{
    protected $model = Playbook::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'code' => 'PB-' . fake()->unique()->numerify('###'),
            'category' => fake()->randomElement(['incident', 'emergency', 'clinical', 'operational']),
            'description' => fake()->sentence(),
            'version' => 1,
            'is_active' => true,
        ];
    }
}
