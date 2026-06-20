<?php

namespace Database\Factories;

use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmergencyDrillFindingFactory extends Factory
{
    protected $model = EmergencyDrillFinding::class;

    public function definition(): array
    {
        return [
            'emergency_drill_id' => EmergencyDrill::factory(),
            'finding_type' => fake()->randomElement(['observation', 'non_conformance', 'improvement', 'positive']),
            'description' => fake()->sentence(10),
            'severity' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'status' => 'open',
            'created_by' => User::factory(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => fake()->sentence(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => 'open',
            'due_date' => now()->subWeek(),
        ]);
    }
}
