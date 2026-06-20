<?php

namespace Database\Factories;

use App\Models\PpeInspection;
use App\Models\PpeInventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PpeInspectionFactory extends Factory
{
    protected $model = PpeInspection::class;

    public function definition(): array
    {
        return [
            'ppe_inventory_id' => PpeInventory::factory(),
            'inspected_by' => User::factory(),
            'inspected_at' => now(),
            'result' => 'pass',
            'condition_after' => 'good',
            'findings' => fake()->optional()->sentence(),
            'action_taken' => null,
            'next_inspection_due' => now()->addMonths(3),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'result' => 'fail',
            'condition_after' => 'poor',
        ]);
    }

    public function condemned(): static
    {
        return $this->state(fn () => [
            'result' => 'condemned',
            'condition_after' => 'condemned',
        ]);
    }
}
