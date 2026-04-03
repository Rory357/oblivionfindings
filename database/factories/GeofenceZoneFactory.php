<?php

namespace Database\Factories;

use App\Models\GeofenceZone;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeofenceZoneFactory extends Factory
{
    protected $model = GeofenceZone::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Zone',
            'latitude' => fake()->latitude(-47, -34),
            'longitude' => fake()->longitude(166, 178),
            'radius_meters' => fake()->numberBetween(50, 500),
            'is_active' => true,
        ];
    }
}
