<?php

namespace Database\Factories;

use App\Domain\Hr\Models\HrComplianceRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrComplianceRequirementFactory extends Factory
{
    protected $model = HrComplianceRequirement::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'code' => 'HR_' . strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(3, true) . ' Certification',
            'category' => fake()->randomElement(['safety', 'clinical', 'operational']),
            'check_type' => 'training_course',
            'validity_months' => 12,
            'hard_stop' => false,
            'is_active' => true,
        ];
    }

    public function hardStop(): static
    {
        return $this->state(fn () => ['hard_stop' => true]);
    }
}
