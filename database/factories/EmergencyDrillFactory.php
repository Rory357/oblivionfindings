<?php

namespace Database\Factories;

use App\Models\EmergencyDrill;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmergencyDrillFactory extends Factory
{
    protected $model = EmergencyDrill::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'drill_type' => fake()->randomElement(['fire', 'earthquake', 'lockdown', 'evacuation', 'tsunami']),
            'title' => fake()->sentence(4),
            'scheduled_at' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'status' => 'scheduled',
            'created_by' => User::factory(),
        ];
    }
}
