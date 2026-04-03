<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\FleetOuting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetOutingFactory extends Factory
{
    protected $model = FleetOuting::class;

    public function definition(): array
    {
        $departure = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'organisation_id' => 1,
            'title' => fake()->sentence(3),
            'destination' => fake()->city(),
            'purpose' => fake()->sentence(),
            'planned_departure' => $departure,
            'planned_return' => fake()->dateTimeBetween($departure, (clone $departure)->modify('+8 hours')),
            'asset_id' => Asset::factory(),
            'driver_user_id' => User::factory(),
            'status' => 'planned',
            'created_by_user_id' => User::factory(),
        ];
    }
}
