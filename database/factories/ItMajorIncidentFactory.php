<?php

namespace Database\Factories;

use App\Models\ItMajorIncident;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItMajorIncident> */
class ItMajorIncidentFactory extends Factory
{
    protected $model = ItMajorIncident::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'ticket_id' => ItTicket::factory()->state([
                'tenant_id' => 1, 'work_type' => 'major_incident', 'workflow_state' => 'declared',
                'status' => 'open', 'source' => 'agent', 'priority' => 'urgent',
            ]),
            'severity' => 'sev2',
            'impact_summary' => fake()->sentence(),
            'commander_user_id' => User::factory()->state(['organization_id' => 1]),
            'communications_lead_user_id' => null,
            'target_update_minutes' => 30,
            'declared_at' => now(),
            'next_update_due_at' => now()->addMinutes(30),
        ];
    }
}
