<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrCourseAssignmentFactory extends Factory
{
    protected $model = HrCourseAssignment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'hr_course_id' => HrCourse::factory(),
            'source' => 'manual',
            'assigned_at' => now(),
            'due_at' => now()->addDays(30),
            'status' => 'assigned',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => 'assigned',
            'due_at' => now()->subDays(5),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'score' => fake()->randomFloat(2, 80, 100),
        ]);
    }
}
