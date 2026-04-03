<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\FleetWorkOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetWorkOrderFactory extends Factory
{
    protected $model = FleetWorkOrder::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'reported_by_user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'category' => fake()->randomElement(['mechanical', 'electrical', 'body_damage', 'tyre', 'scheduled_service']),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'urgent']),
            'status' => fake()->randomElement(['open', 'in_progress', 'completed']),
            'description' => fake()->paragraph(),
        ];
    }
}
