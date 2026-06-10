<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrCourseEnrollmentFactory extends Factory
{
    protected $model = HrCourseEnrollment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'course_id' => HrCourse::factory(),
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'score' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
            'score' => fake()->randomFloat(2, 80, 100),
        ]);
    }
}
