<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrJobPosting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrJobPostingFactory extends Factory
{
    protected $model = HrJobPosting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->jobTitle(),
            'slug' => fake()->unique()->slug(),
            'description' => fake()->paragraphs(2, true),
            'department' => fake()->randomElement(['Nursing', 'Administration', 'Support Services', 'Clinical', 'Management']),
            'location' => fake()->city(),
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'casual', 'fixed_term']),
            'status' => 'published',
            'salary_range_min' => $min = fake()->numberBetween(45000, 80000),
            'salary_range_max' => $min + fake()->numberBetween(5000, 25000),
            'created_by' => User::factory(),
        ];
    }
}
