<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrPositionFactory extends Factory
{
    protected $model = HrPosition::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->jobTitle(),
            'code' => fake()->unique()->bothify('POS-####'),
            'department' => fake()->randomElement(['Nursing', 'Administration', 'Support Services', 'Clinical', 'Management']),
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'casual', 'fixed_term']),
            'is_active' => true,
        ];
    }
}
