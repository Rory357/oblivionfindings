<?php

namespace Database\Factories;

use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SafeguardingConcern>
 */
class SafeguardingConcernFactory extends Factory
{
    protected $model = SafeguardingConcern::class;

    public function definition(): array
    {
        return [
            'concern_type' => fake()->randomElement(['abuse', 'neglect', 'self_neglect', 'exploitation', 'discrimination']),
            'abuse_category' => fake()->randomElement(['physical', 'emotional', 'sexual', 'financial', 'organisational']),
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'description' => fake()->paragraph(),
            'occurred_at' => now()->subDays(fake()->numberBetween(0, 7)),
            'location' => fake()->address(),
            'reported_by_user_id' => User::factory(),
            'reported_at' => now(),
            'status' => 'reported',
            'subject_name' => fake()->name(),
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }

    public function high(): static
    {
        return $this->state(fn () => ['severity' => 'high']);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'reported']);
    }

    public function investigating(): static
    {
        return $this->state(fn () => ['status' => 'investigating']);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by_user_id' => User::factory(),
            'closure_summary' => 'Concern resolved.',
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => [
            'assigned_to_user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }

    public function withSite(Site $site): static
    {
        return $this->state(fn () => ['site_id' => $site->id]);
    }

    public function requiresExternalReferral(): static
    {
        return $this->state(fn () => ['requires_external_referral' => true]);
    }
}
