<?php

namespace Database\Factories\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Enums\AlertSeverity;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalEvent>
 */
class ClinicalEventFactory extends Factory
{
    protected $model = ClinicalEvent::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'reported_by' => User::factory(),
            'event_type' => fake()->randomElement(ClinicalEventType::cases()),
            'severity' => fake()->randomElement(AlertSeverity::ALL),
            'occurred_at' => now(),
            'reported_at' => now(),
            'description' => fake()->sentence(),
            'status' => 'open',
            'requires_followup' => false,
        ];
    }

    public function fall(): static
    {
        return $this->state(fn () => [
            'event_type' => ClinicalEventType::Fall,
            'severity' => AlertSeverity::MEDIUM,
            'description' => 'Client had a fall.',
        ]);
    }

    public function seizure(): static
    {
        return $this->state(fn () => [
            'event_type' => ClinicalEventType::Seizure,
            'severity' => AlertSeverity::HIGH,
            'description' => 'Seizure episode observed.',
        ]);
    }

    public function highSeverity(): static
    {
        return $this->state(fn () => [
            'severity' => AlertSeverity::HIGH,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => AlertSeverity::CRITICAL,
        ]);
    }

    public function withFollowup(): static
    {
        return $this->state(fn () => [
            'requires_followup' => true,
        ]);
    }

    public function reviewed(): static
    {
        return $this->state(fn () => [
            'status' => 'reviewed',
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function forShift(int $shiftId): static
    {
        return $this->state(fn () => [
            'shift_id' => $shiftId,
        ]);
    }
}
