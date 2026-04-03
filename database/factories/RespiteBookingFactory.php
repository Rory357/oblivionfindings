<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RespiteBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

class RespiteBookingFactory extends Factory
{
    protected $model = RespiteBooking::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 day', '+2 months');

        return [
            'client_id' => Client::factory(),
            'start_at' => $start,
            'end_at' => fake()->dateTimeBetween($start, (clone $start)->modify('+14 days')),
            'status' => fake()->randomElement(['pending', 'confirmed', 'in_progress', 'completed', 'cancelled']),
        ];
    }
}
