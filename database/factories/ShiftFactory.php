<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
