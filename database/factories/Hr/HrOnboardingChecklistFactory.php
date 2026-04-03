<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrOnboardingChecklist;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrOnboardingChecklistFactory extends Factory
{
    protected $model = HrOnboardingChecklist::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'employee_profile_id' => 1,
            'template_key' => fake()->randomElement(['standard', 'clinical', 'management', 'contractor']),
            'status' => fake()->randomElement(['pending', 'in_progress', 'completed']),
        ];
    }
}
