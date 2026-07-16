<?php

namespace Database\Factories;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HsCorrectiveActionFactory extends Factory
{
    protected $model = HsCorrectiveAction::class;

    public function definition(): array
    {
        return [
            'hs_event_id' => HsEvent::factory()->high(),
            'source_control_room_task_id' => null,
            'reference_number' => HsCorrectiveAction::generateReferenceNumber(),
            'action_type' => HsCorrectiveAction::TYPE_CORRECTIVE,
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'title' => fake()->sentence(6),
            'status' => HsCorrectiveAction::STATUS_OPEN,
            'due_date' => now()->addDays(14),
            'created_by' => User::factory(),
        ];
    }

    public function fromInvestigation(?HsInvestigation $investigation = null): static
    {
        return $this->state(function () use ($investigation) {
            $inv = $investigation ?? HsInvestigation::factory()->withFindings()->create();

            return [
                'hs_event_id' => $inv->hs_event_id,
                'hs_investigation_id' => $inv->id,
                'recommendation_index' => 0,
            ];
        });
    }

    public function assigned(): static
    {
        return $this->state(fn () => [
            'assigned_to_user_id' => User::factory(),
            'assigned_by_user_id' => User::factory(),
            'assigned_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->assigned()->state(fn () => [
            'status' => HsCorrectiveAction::STATUS_IN_PROGRESS,
        ]);
    }

    public function completed(): static
    {
        return $this->inProgress()->state(fn () => [
            'status' => HsCorrectiveAction::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by_user_id' => User::factory(),
            'completion_notes' => fake()->sentence(),
        ]);
    }

    public function verified(): static
    {
        return $this->completed()->state(fn () => [
            'status' => HsCorrectiveAction::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by_user_id' => User::factory(),
            'verification_notes' => 'Effectiveness confirmed.',
            'effectiveness_confirmed' => true,
        ]);
    }

    public function closed(): static
    {
        return $this->verified()->state(fn () => [
            'status' => HsCorrectiveAction::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => User::factory(),
        ]);
    }

    public function overdue(): static
    {
        return $this->inProgress()->state(fn () => [
            'due_date' => now()->subDays(3),
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn () => [
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
        ]);
    }
}
