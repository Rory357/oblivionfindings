<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftHandoverFactory extends Factory
{
    protected $model = ShiftHandover::class;

    public function definition(): array
    {
        $outgoingStaff = User::factory();
        $incomingStaff = User::factory();

        return [
            // Default to organization 1 to match `User::getOrganizationIdAttribute`,
            // which returns 1 when the user has no `organization_id` attribute. The
            // handover controllers scope queries by the auth user's organization_id,
            // so a null factory default left handovers invisible to test users.
            'organization_id' => 1,
            'outgoing_shift_id' => Shift::factory(),
            'incoming_shift_id' => Shift::factory(),
            'client_id' => Client::factory(),
            'outgoing_staff_id' => $outgoingStaff,
            'incoming_staff_id' => $incomingStaff,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'handover_notes' => fake()->paragraph(),
            'client_mood' => fake()->optional()->randomElement(['settled', 'anxious', 'tired']),
            'tasks_pending' => [],
            'medications_due' => [],
            'incidents_to_note' => [],
            'follow_up_items' => [],
            'submitted_at' => now(),
            'submitted_by' => $outgoingStaff,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'submitted_at' => null,
            'submitted_by' => null,
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'submitted_at' => $attributes['submitted_at'] ?? now()->subMinutes(30),
            'submitted_by' => $attributes['submitted_by'] ?? $attributes['outgoing_staff_id'] ?? User::factory(),
            'acknowledged_at' => now(),
            'acknowledged_by' => $attributes['incoming_staff_id'] ?? User::factory(),
        ]);
    }
}
