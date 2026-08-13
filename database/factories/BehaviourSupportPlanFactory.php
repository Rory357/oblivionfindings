<?php

namespace Database\Factories;

use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BehaviourSupportPlanFactory extends Factory
{
    protected $model = BehaviourSupportPlan::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory()->for(Site::factory()),
            'title' => 'Behaviour support plan — '.fake()->lastName(),
            'triggers' => fake()->sentence(),
            'de_escalation_strategies' => fake()->sentence(),
            'approved_interventions' => "Verbal de-escalation\nOffer quiet space\nPRN as charted",
            'prohibited_interventions' => "Prone restraint\nSeclusion over 30 min",
            'restrictive_practice_type' => fake()->randomElement(['physical', 'chemical', 'mechanical', 'seclusion', 'environmental']),
            'developed_by' => User::factory(),
            'developed_at' => now()->subMonths(2),
            'review_date' => now()->addMonths(4),
            'status' => 'active',
            'created_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function underReview(): static
    {
        return $this->state(fn () => ['status' => 'under_review']);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived']);
    }

    public function reviewDue(): static
    {
        return $this->state(fn () => ['status' => 'active', 'review_date' => now()->subDays(2)]);
    }
}
