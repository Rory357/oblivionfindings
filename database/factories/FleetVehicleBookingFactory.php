<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetVehicleBookingFactory extends Factory
{
    protected $model = FleetVehicleBooking::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'asset_id' => Asset::factory(),
            'user_id' => User::factory(),
            'purpose' => fake()->sentence(),
            'starts_at' => $start,
            'ends_at' => fake()->dateTimeBetween($start, (clone $start)->modify('+8 hours')),
            'status' => fake()->randomElement(['pending', 'approved', 'active', 'completed']),
        ];
    }
}
