<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkCapacityAssessmentFactory extends Factory
{
    protected $model = WorkCapacityAssessment::class;

    public function definition(): array
    {
        return [
            'workplace_injury_id' => WorkplaceInjury::factory(),
            'user_id' => User::factory(),
            'assessment_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'assessor_name' => fake()->name(),
            'assessor_type' => fake()->randomElement(['gp', 'specialist', 'physiotherapist', 'occupational_therapist', 'employer']),
            'capacity_status' => fake()->randomElement(['fit_for_full_duties', 'fit_for_modified_duties', 'unfit_for_work', 'requires_review']),
            'restrictions' => fake()->sentence(),
            'recommendations' => fake()->sentence(),
            'next_assessment_date' => null,
            'assessment_summary' => fake()->paragraph(),
        ];
    }
}
