<?php

namespace Database\Factories;

use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PpeAllocationFactory extends Factory
{
    protected $model = PpeAllocation::class;

    public function definition(): array
    {
        return [
            'ppe_inventory_id' => PpeInventory::factory()->allocated(),
            'user_id' => User::factory(),
            'allocated_at' => now(),
            'returned_at' => null,
            'fit_test_completed' => false,
            'fit_test_date' => null,
            'fit_test_result' => null,
            'training_completed' => false,
            'training_date' => null,
            'acknowledged' => false,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'notes' => null,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);
    }

    public function unacknowledged(): static
    {
        return $this->state(fn () => [
            'acknowledged' => false,
            'acknowledged_at' => null,
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn () => ['returned_at' => now()]);
    }

    public function fitTested(): static
    {
        return $this->state(fn () => [
            'fit_test_completed' => true,
            'fit_test_date' => now()->subWeek(),
            'fit_test_result' => 'pass',
        ]);
    }
}
