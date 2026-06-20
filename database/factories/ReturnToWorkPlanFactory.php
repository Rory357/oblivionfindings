<?php

namespace Database\Factories;

use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReturnToWorkPlanFactory extends Factory
{
    protected $model = ReturnToWorkPlan::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'workplace_injury_id' => WorkplaceInjury::factory(),
            'worker_id' => User::factory(),
            'manager_id' => null,
            'plan_start_date' => $start,
            'plan_end_date' => null,
            'status' => 'active',
            'goals' => ['Return to full pre-injury duties', 'Maintain regular contact with the worker'],
            'stages' => [
                [
                    'name' => 'Graduated hours',
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => null,
                    'hours_per_week' => 20,
                    'duties_description' => 'Light administrative duties, no manual handling.',
                ],
            ],
            'medical_clearance_notes' => null,
            'medical_clearance_provider' => null,
            'next_review_date' => null,
        ];
    }
}
