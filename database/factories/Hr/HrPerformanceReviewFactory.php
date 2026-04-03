<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrPerformanceReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrPerformanceReviewFactory extends Factory
{
    protected $model = HrPerformanceReview::class;

    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-6 months', '-1 month');
        $periodEnd = (clone $periodStart)->modify('+6 months');

        return [
            'tenant_id' => 1,
            'employee_user_id' => User::factory(),
            'reviewer_user_id' => User::factory(),
            'review_period_start' => $periodStart,
            'review_period_end' => $periodEnd,
            'status' => fake()->randomElement(['draft', 'in_progress', 'completed', 'acknowledged']),
            'review_type' => fake()->randomElement(['annual', 'probation', 'mid_year', 'quarterly']),
        ];
    }
}
