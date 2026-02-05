<?php

namespace Database\Factories;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ControlRoomAlert>
 */
class ControlRoomAlertFactory extends Factory
{
    protected $model = ControlRoomAlert::class;

    public function definition(): array
    {
        $sources = ['fleet', 'personal_tracker', 'manual', 'external', 'compliance', 'other'];
        $severities = ['low', 'medium', 'high', 'critical'];
        $alertTypes = [
            'Speeding', 'Geofence Exit', 'SOS Button', 'Fall Detected',
            'Device Offline', 'Fire Alarm', 'Door Forced', 'Bed Exit',
            'Training Expired', 'Medication Error', 'Safeguarding Concern',
        ];

        return [
            'source' => fake()->randomElement($sources),
            'alert_type' => fake()->randomElement($alertTypes),
            'severity' => fake()->randomElement($severities),
            'status' => 'open',
            'triggered_at' => now()->subMinutes(fake()->numberBetween(1, 1440)),
            'escalation_level' => 0,
            'context' => [],
            'notes' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'status' => 'ack',
            'acknowledged_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'acknowledged_by_user_id' => User::factory(),
        ]);
    }

    public function triaging(): static
    {
        return $this->state(fn () => [
            'status' => 'triaging',
            'acknowledged_at' => now()->subMinutes(60),
            'acknowledged_by_user_id' => User::factory(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'acknowledged_at' => now()->subHours(2),
            'acknowledged_by_user_id' => User::factory(),
            'resolved_at' => now()->subMinutes(30),
            'resolved_by_user_id' => User::factory(),
            'notes' => 'Resolved successfully',
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'acknowledged_at' => now()->subHours(3),
            'acknowledged_by_user_id' => User::factory(),
            'resolved_at' => now()->subHours(1),
            'resolved_by_user_id' => User::factory(),
            'closed_at' => now()->subMinutes(15),
            'closed_by_user_id' => User::factory(),
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }

    public function high(): static
    {
        return $this->state(fn () => ['severity' => 'high']);
    }

    public function low(): static
    {
        return $this->state(fn () => ['severity' => 'low']);
    }

    public function escalated(int $level = 1): static
    {
        return $this->state(fn () => [
            'escalation_level' => $level,
            'escalated_at' => now()->subMinutes(30),
            'escalated_by_user_id' => User::factory(),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => [
            'assigned_to_user_id' => $user->id,
            'assigned_at' => now()->subMinutes(10),
            'assigned_by_user_id' => User::factory(),
        ]);
    }

    public function fromFleet(): static
    {
        return $this->state(fn () => [
            'source' => 'fleet',
            'alert_type' => fake()->randomElement(['Speeding', 'Geofence Exit', 'Device Offline']),
        ]);
    }

    public function fromCompliance(): static
    {
        return $this->state(fn () => [
            'source' => 'compliance',
            'alert_type' => fake()->randomElement(['Training Expired', 'Medication Error', 'Safeguarding Concern']),
        ]);
    }

    public function fromPersonalTracker(): static
    {
        return $this->state(fn () => [
            'source' => 'personal_tracker',
            'alert_type' => fake()->randomElement(['SOS Button', 'Fall Detected', 'Check In Missed']),
        ]);
    }

    public function withNotes(string $notes = 'Test notes'): static
    {
        return $this->state(fn () => ['notes' => $notes]);
    }

    public function triggeredDaysAgo(int $days): static
    {
        return $this->state(fn () => [
            'triggered_at' => now()->subDays($days)->subHours(fake()->numberBetween(0, 23)),
        ]);
    }
}
