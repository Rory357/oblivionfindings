<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrCaseFactory extends Factory
{
    protected $model = HrCase::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'case_number' => fake()->unique()->bothify('CASE-####'),
            'user_id' => User::factory(),
            'case_type' => fake()->randomElement(['grievance', 'disciplinary', 'investigation', 'welfare', 'general']),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraphs(2, true),
            'opened_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'status' => fake()->randomElement(['open', 'in_progress', 'resolved', 'closed']),
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
        ];
    }
}
