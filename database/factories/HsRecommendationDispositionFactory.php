<?php

namespace Database\Factories;

use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use Illuminate\Database\Eloquent\Factories\Factory;

class HsRecommendationDispositionFactory extends Factory
{
    protected $model = HsRecommendationDisposition::class;

    public function definition(): array
    {
        return [
            'hs_investigation_id' => HsInvestigation::factory()->withFindings(),
            'recommendation_index' => 0,
            'disposition' => HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
            'reason' => fake()->sentence(),
            'decided_at' => now(),
        ];
    }
}
