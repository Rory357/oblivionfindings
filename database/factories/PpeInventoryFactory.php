<?php

namespace Database\Factories;

use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class PpeInventoryFactory extends Factory
{
    protected $model = PpeInventory::class;

    public function definition(): array
    {
        return [
            'ppe_type_id' => PpeType::factory(),
            'site_id' => Site::factory(),
            'brand' => fake()->company(),
            'model' => fake()->bothify('Model-###'),
            'serial_number' => fake()->unique()->bothify('SN-#####'),
            'purchase_date' => fake()->dateTimeBetween('-2 years', '-1 month'),
            'expiry_date' => fake()->dateTimeBetween('+6 months', '+3 years'),
            'condition' => 'good',
            'quantity' => 1,
            'location' => fake()->randomElement(['Store room', 'PPE cabinet', 'Vehicle', 'Workshop']),
            'status' => 'available',
            'last_inspected_at' => null,
            'next_inspection_due' => fake()->dateTimeBetween('+1 month', '+6 months'),
        ];
    }

    public function allocated(): static
    {
        return $this->state(fn () => ['status' => 'allocated']);
    }

    public function condemned(): static
    {
        return $this->state(fn () => ['status' => 'condemned', 'condition' => 'condemned']);
    }

    public function disposed(): static
    {
        return $this->state(fn () => ['status' => 'disposed', 'condition' => 'condemned']);
    }

    /** Inspection due/overdue (yesterday). */
    public function inspectionDue(): static
    {
        return $this->state(fn () => ['next_inspection_due' => now()->subDay()]);
    }

    /** Expiring within 60 days. */
    public function expiring(): static
    {
        return $this->state(fn () => ['expiry_date' => now()->addDays(30)]);
    }

    /** Already expired. */
    public function expired(): static
    {
        return $this->state(fn () => ['expiry_date' => now()->subDay()]);
    }
}
