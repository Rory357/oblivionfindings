<?php

namespace Database\Factories;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftHandoverFactory extends Factory
{
    protected $model = ShiftHandover::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory()->state(['site_id' => Site::factory()]),
            'outgoing_staff_id' => fn (array $attributes) => $this->createSiteWorker($attributes['client_id']),
            'incoming_staff_id' => fn (array $attributes) => $this->createSiteWorker($attributes['client_id']),
            'outgoing_shift_id' => fn (array $attributes) => Shift::factory()->create([
                'client_id' => $attributes['client_id'],
                'site_id' => Client::query()->findOrFail($attributes['client_id'])->site_id,
                'user_id' => $attributes['outgoing_staff_id'],
            ])->id,
            // An incoming recipient may be nominated before a next Shift is
            // scheduled. Tests that supply an incoming Shift must also supply
            // its matching Client, Site, and assigned staff member.
            'incoming_shift_id' => null,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'handover_notes' => fake()->paragraph(),
            'client_mood' => fake()->optional()->randomElement(['settled', 'anxious', 'tired']),
            'tasks_pending' => [],
            'medications_due' => [],
            'incidents_to_note' => [],
            'follow_up_items' => [],
            'submitted_at' => now(),
            'submitted_by' => fn (array $attributes) => $attributes['outgoing_staff_id'],
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
            'incoming_shift_id' => $attributes['incoming_shift_id'] ?? Shift::factory()->create([
                'client_id' => $attributes['client_id'],
                'site_id' => Client::query()->findOrFail($attributes['client_id'])->site_id,
                'user_id' => $attributes['incoming_staff_id'],
            ])->id,
            'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
            'submitted_at' => $attributes['submitted_at'] ?? now()->subMinutes(30),
            'submitted_by' => $attributes['submitted_by'] ?? $attributes['outgoing_staff_id'] ?? User::factory(),
            'acknowledged_at' => now(),
            'acknowledged_by' => $attributes['incoming_staff_id'] ?? User::factory(),
        ]);
    }

    private function createSiteWorker(int $clientId): int
    {
        $client = Client::query()->findOrFail($clientId);
        $worker = User::factory()->frontlineWorker()->create();

        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'employee_number' => 'EMP-HANDOVER-'.$worker->id,
            'work_email' => $worker->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $client->site_id,
            'secondary_site_ids' => [],
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ]);

        return (int) $worker->id;
    }
}
