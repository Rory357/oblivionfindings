<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrSuccessionPlanFactory extends Factory
{
    protected $model = HrSuccessionPlan::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'role_title' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Nursing', 'Administration', 'Support Services', 'Clinical', 'Management']),
            'risk_level' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'is_active' => true,
        ];
    }
}
