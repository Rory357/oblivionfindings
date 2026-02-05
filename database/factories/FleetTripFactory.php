<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\FleetTrip;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FleetTrip>
 */
class FleetTripFactory extends Factory
{
    protected $model = FleetTrip::class;

    public function definition(): array
    {
        $startedAt = now()->subHours(fake()->numberBetween(1, 48));

        return [
            'asset_id' => Asset::factory()->state(['category' => 'vehicle'])->for(Site::factory(), 'site'),
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(fake()->numberBetween(15, 180)),
            'start_latitude' => fake()->latitude(-37, -36),
            'start_longitude' => fake()->longitude(174, 176),
            'end_latitude' => fake()->latitude(-37, -36),
            'end_longitude' => fake()->longitude(174, 176),
            'distance_km' => fake()->randomFloat(3, 1, 100),
            'duration_s' => fake()->numberBetween(600, 10800),
            'status' => 'open',
            'consent_blocked' => false,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'open',
            'ended_at' => null,
        ]);
    }
}
