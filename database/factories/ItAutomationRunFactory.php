<?php

namespace Database\Factories;

use App\Models\ItAutomationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItAutomationRun> */
class ItAutomationRunFactory extends Factory
{
    protected $model = ItAutomationRun::class;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'automation_key' => $this->faker->randomElement(['it.check-sla', 'it.close-resolved', 'it.poll-mailbox']),
            'schedule_expression' => '0 * * * *',
            'status' => 'succeeded',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'runtime_ms' => 1000,
        ];
    }
}
