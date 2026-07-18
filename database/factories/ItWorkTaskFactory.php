<?php

namespace Database\Factories;

use App\Models\ItTicket;
use App\Models\ItWorkTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItWorkTask>
 */
class ItWorkTaskFactory extends Factory
{
    protected $model = ItWorkTask::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'ticket_id' => ItTicket::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'status' => 'pending',
            'is_required' => true,
            'evidence_required' => false,
            'sort_order' => 0,
        ];
    }
}
