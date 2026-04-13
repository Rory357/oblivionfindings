<?php

namespace Database\Factories\Clinical;

use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalProtocolSchedule>
 */
class ClinicalProtocolScheduleFactory extends Factory
{
    protected $model = ClinicalProtocolSchedule::class;

    public function definition(): array
    {
        return [
            'clinical_protocol_id' => ClinicalProtocol::factory(),
            'due_at' => now()->addHours(fake()->numberBetween(1, 48)),
            'status' => 'pending',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_at' => now()->subHours(fake()->numberBetween(1, 24)),
            'status' => 'pending',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => \App\Models\User::factory(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'skip_reason' => 'Client not available',
        ]);
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'due_at' => now()->subHours(48),
            'status' => 'missed',
        ]);
    }
}
