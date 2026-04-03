<?php

namespace Database\Factories;

use App\Models\PrivacyImpactAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrivacyImpactAssessmentFactory extends Factory
{
    protected $model = PrivacyImpactAssessment::class;

    public function definition(): array
    {
        return [
            'assessment_name' => fake()->sentence(4),
            'project_or_process' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'assessment_type' => fake()->randomElement(['new_project', 'process_change', 'system_upgrade', 'periodic_review']),
            'assessment_date' => fake()->date(),
            'processing_purpose' => fake()->sentence(),
            'legal_basis' => fake()->randomElement(['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests']),
            'overall_risk_level' => fake()->randomElement(['low', 'medium', 'high', 'very_high']),
            'residual_risk_level' => fake()->randomElement(['low', 'medium', 'high', 'very_high']),
        ];
    }
}
