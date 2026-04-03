<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrGoalFactory extends Factory
{
    protected $model = HrGoal::class;

    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-1 month', '+1 month');
        $dueDate = (clone $startDate)->modify('+' . fake()->numberBetween(30, 180) . ' days');

        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'title' => fake()->sentence(5),
            'goal_type' => fake()->randomElement(['individual', 'team', 'department', 'okr']),
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'created_by' => User::factory(),
            'status' => fake()->randomElement(['not_started', 'in_progress', 'completed', 'cancelled']),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
        ];
    }
}
