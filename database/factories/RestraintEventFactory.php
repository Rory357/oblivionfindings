<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestraintEventFactory extends Factory
{
    protected $model = RestraintEvent::class;

    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'client_id' => Client::factory(),
            'site_id' => Site::factory(),
            'started_at' => $startedAt,
            'ended_at' => (clone $startedAt)->modify('+'.fake()->numberBetween(1, 30).' minutes'),
            'duration_minutes' => fake()->numberBetween(1, 30),
            'restraint_type' => fake()->randomElement(['physical', 'mechanical', 'seclusion', 'chemical']),
            'severity' => fake()->randomElement(['low', 'medium', 'high']),
            'trigger_description' => fake()->sentence(),
            'de_escalation_attempted' => fake()->sentence(),
            'restraint_description' => fake()->paragraph(),
            'injury_occurred' => false,
            'within_support_plan' => true,
            'created_by' => User::factory(),
        ];
    }

    public function withInjury(): static
    {
        return $this->state(fn () => [
            'injury_occurred' => true,
            'injury_details' => fake()->sentence(),
        ]);
    }

    public function outsideSupportPlan(): static
    {
        return $this->state(fn () => [
            'within_support_plan' => false,
            'deviation_reason' => fake()->sentence(),
        ]);
    }

    public function withinSupportPlan(): static
    {
        return $this->state(fn () => [
            'within_support_plan' => true,
            'injury_occurred' => false,
        ]);
    }
}
