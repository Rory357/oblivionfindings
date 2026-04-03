<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrCompensationReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrCompensationReviewFactory extends Factory
{
    protected $model = HrCompensationReview::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->sentence(4),
            'review_cycle' => fake()->randomElement(['annual', 'mid_year', 'quarterly', 'ad_hoc']),
            'effective_date' => fake()->dateTimeBetween('+1 month', '+6 months'),
            'status' => fake()->randomElement(['draft', 'in_progress', 'approved', 'completed']),
        ];
    }
}
