<?php

namespace Database\Factories;

use App\Models\ItProblem;
use App\Models\ItTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItProblem> */
class ItProblemFactory extends Factory
{
    protected $model = ItProblem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'ticket_id' => ItTicket::factory()->state([
                'tenant_id' => 1,
                'work_type' => 'problem',
                'workflow_state' => 'investigating',
                'status' => 'in_progress',
                'source' => 'agent',
            ]),
            'impact_summary' => fake()->sentence(),
            'root_cause' => null,
            'workaround' => null,
            'corrective_action' => null,
        ];
    }
}
