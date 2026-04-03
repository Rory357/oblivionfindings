<?php

namespace Database\Factories;

use App\Models\TrainingCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingCourseFactory extends Factory
{
    protected $model = TrainingCourse::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'category' => fake()->randomElement(['clinical', 'safety', 'compliance', 'management', 'induction']),
            'active' => true,
        ];
    }
}
