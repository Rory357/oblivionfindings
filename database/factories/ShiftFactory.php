<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+1 week');
        $end = (clone $start)->modify('+4 hours');

        return [
            'client_id' => Client::factory(),
            'service_context_id' => ServiceContext::factory(),
            'user_id' => User::factory(),
            'starts_at' => $start,
            'ends_at' => $end,
            'location' => fake()->optional()->address(),
            'notes' => fake()->optional()->paragraph(),
            'status' => 'scheduled',
            'created_by' => User::factory(),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'scheduled',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
            'actual_starts_at' => now(),
            'started_by' => $attributes['user_id'] ?? User::factory(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'actual_starts_at' => $attributes['starts_at'] ?? now(),
            'actual_ends_at' => $attributes['ends_at'] ?? now()->addHours(4),
            'started_by' => $attributes['user_id'] ?? User::factory(),
            'completed_by' => $attributes['user_id'] ?? User::factory(),
        ]);
    }

    public function unassigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    public function forSite(?Site $site = null): static
    {
        return $this->state(fn () => [
            'site_id' => $site?->id ?? Site::factory(),
        ]);
    }

    public function published(?RosterPeriod $period = null): static
    {
        return $this->state(fn () => [
            'roster_period_id' => $period?->id,
            'published_at' => now(),
            'publish_dirty_at' => null,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => [
            'published_at' => null,
            'publish_dirty_at' => null,
        ]);
    }

    public function assignedToday(?User $user = null, ?Carbon $start = null): static
    {
        return $this->state(function (array $attributes) use ($user, $start) {
            $start ??= Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
                ->setTime(9, 0);

            return [
                'user_id' => $user?->id ?? ($attributes['user_id'] ?? User::factory()),
                'starts_at' => $start->copy()->utc(),
                'ends_at' => $start->copy()->addHours(4)->utc(),
                'status' => 'scheduled',
            ];
        });
    }
}
