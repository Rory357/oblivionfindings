<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrApplicationFactory extends Factory
{
    protected $model = HrApplication::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'candidate_id' => HrCandidate::factory(),
            'position_title' => fake()->randomElement(['Support Worker', 'Team Leader', 'Coordinator']),
            'position_role' => fake()->randomElement(['support_worker', 'team_lead', 'coordinator']),
            'cover_letter' => fake()->optional()->paragraph(),
            'answers' => [],
            'screening_answers' => [],
            'status' => 'active',
        ];
    }
}
