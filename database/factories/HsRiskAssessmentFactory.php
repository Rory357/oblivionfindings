<?php

namespace Database\Factories;

use App\Models\HsRiskAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HsRiskAssessmentFactory extends Factory
{
    protected $model = HsRiskAssessment::class;

    public function definition(): array
    {
        $likelihood = fake()->numberBetween(1, 5);
        $consequence = fake()->numberBetween(1, 5);
        $score = $likelihood * $consequence;

        return [
            'reference_number' => HsRiskAssessment::generateReferenceNumber(),
            'title' => fake()->sentence(4),
            'risk_description' => fake()->paragraph(),
            'status' => HsRiskAssessment::STATUS_DRAFT,
            'likelihood' => $likelihood,
            'consequence' => $consequence,
            'risk_score' => $score,
            'risk_level' => HsRiskAssessment::scoreToLevel($score),
            'assessed_by_user_id' => User::factory(),
            'assessed_at' => now(),
            'created_by' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => HsRiskAssessment::STATUS_ACTIVE,
            'approved_by_user_id' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function highRisk(): static
    {
        return $this->state(function () {
            $likelihood = 4;
            $consequence = 3;
            $score = $likelihood * $consequence;

            return [
                'likelihood' => $likelihood,
                'consequence' => $consequence,
                'risk_score' => $score,
                'risk_level' => HsRiskAssessment::scoreToLevel($score),
            ];
        });
    }

    public function extremeRisk(): static
    {
        return $this->state(function () {
            return [
                'likelihood' => 5,
                'consequence' => 4,
                'risk_score' => 20,
                'risk_level' => HsRiskAssessment::LEVEL_EXTREME,
            ];
        });
    }

    public function dueForReview(): static
    {
        return $this->active()->state(fn () => [
            'review_due_at' => now()->subDays(1),
        ]);
    }

    public function forSite(int $siteId): static
    {
        return $this->state(fn () => [
            'assessable_type' => 'App\\Models\\Site',
            'assessable_id' => $siteId,
        ]);
    }

    public function forClient(int $clientId): static
    {
        return $this->state(fn () => [
            'assessable_type' => 'App\\Models\\Client',
            'assessable_id' => $clientId,
        ]);
    }
}
