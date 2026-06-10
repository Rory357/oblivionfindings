<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrCourseFactory extends Factory
{
    protected $model = HrCourse::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->sentence(4),
            'code' => fake()->unique()->bothify('HR-CSE-####'),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(['Clinical', 'Health and Safety', 'Induction', 'Leadership']),
            'delivery_method' => fake()->randomElement(['online', 'in_person', 'blended', 'self_paced']),
            'duration_hours' => fake()->randomFloat(1, 1, 16),
            'provider' => fake()->company(),
            'cost' => fake()->randomFloat(2, 0, 500),
            'is_mandatory' => fake()->boolean(40),
            'max_participants' => fake()->numberBetween(8, 30),
            'is_active' => true,
        ];
    }
}
