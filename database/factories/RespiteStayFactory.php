<?php

namespace Database\Factories;

use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RespiteStay> */
class RespiteStayFactory extends Factory
{
    protected $model = RespiteStay::class;

    public function definition(): array
    {
        return [
            'booking_id' => RespiteBooking::factory(),
            // A stay belongs to its booking's client unless a test pins both.
            'client_id' => fn (array $attributes) => RespiteBooking::query()->findOrFail($attributes['booking_id'])->client_id,
            'status' => 'active',
            'actual_start' => now()->subDay(),
        ];
    }
}
