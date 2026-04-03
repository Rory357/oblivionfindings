<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrSurveyFactory extends Factory
{
    protected $model = HrSurvey::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->sentence(4),
            'survey_type' => fake()->randomElement(['engagement', 'pulse', 'exit', 'onboarding', 'custom']),
            'status' => fake()->randomElement(['draft', 'active', 'closed']),
            'is_anonymous' => fake()->boolean(50),
        ];
    }
}
