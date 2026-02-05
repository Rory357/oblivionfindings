<?php

namespace Database\Factories;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncidentFollowupFactory extends Factory
{
    protected $model = IncidentFollowup::class;

    public function definition(): array
    {
        return [
            'client_incident_id' => ClientIncident::factory(),
            'assigned_to_user_id' => User::factory(),
            'due_at' => fake()->dateTimeBetween('now', '+1 week'),
            'completed_at' => null,
            'notes' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
        ]);
    }
}
