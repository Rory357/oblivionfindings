<?php

namespace Database\Factories;

use App\Models\ItChange;
use App\Models\ItTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItChange> */
class ItChangeFactory extends Factory
{
    protected $model = ItChange::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'ticket_id' => $this->ticketFactory('normal', true),
            'change_type' => 'normal',
            'risk_level' => 'medium',
            'is_restricted' => false,
            'impact_summary' => fake()->sentence(),
        ];
    }

    public function standard(): static
    {
        return $this->state(fn () => [
            'ticket_id' => $this->ticketFactory('standard', false),
            'change_type' => 'standard',
            'risk_level' => 'low',
            'is_restricted' => false,
        ]);
    }

    public function normal(): static
    {
        return $this->state(fn () => [
            'ticket_id' => $this->ticketFactory('normal', true),
            'change_type' => 'normal',
        ]);
    }

    public function emergency(): static
    {
        return $this->state(fn () => [
            'ticket_id' => $this->ticketFactory('emergency', true),
            'change_type' => 'emergency',
            'risk_level' => 'critical',
        ]);
    }

    private function ticketFactory(string $type, bool $requiresApproval): Factory
    {
        return ItTicket::factory()->state([
            'tenant_id' => 1,
            'work_type' => 'change',
            'workflow_state' => 'draft',
            'status' => 'open',
            'source' => 'agent',
            'requires_approval' => $requiresApproval,
            'title' => ucfirst($type).' infrastructure change',
        ]);
    }
}
