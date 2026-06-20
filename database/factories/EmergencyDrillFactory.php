<?php

namespace Database\Factories;

use App\Models\EmergencyDrill;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmergencyDrillFactory extends Factory
{
    protected $model = EmergencyDrill::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'drill_type' => fake()->randomElement(['fire_evacuation', 'earthquake', 'lockdown', 'tsunami']),
            'title' => fake()->sentence(4),
            'scheduled_at' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'status' => 'scheduled',
            'created_by' => User::factory(),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => 'scheduled',
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+2 months'),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'scheduled_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ]);
    }

    public function completed(string $outcome = 'passed'): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'scheduled_at' => now()->subDays(7),
            'started_at' => now()->subDays(7),
            'completed_at' => now()->subDays(7),
            'duration_minutes' => fake()->numberBetween(5, 20),
            'evacuation_time_seconds' => fake()->numberBetween(60, 360),
            'outcome' => $outcome,
            'total_participants' => fake()->numberBetween(4, 12),
            'residents_evacuated' => fake()->numberBetween(2, 8),
            'all_areas_checked' => true,
            'assembly_point_reached' => true,
            'roll_call_completed' => true,
        ]);
    }
}
