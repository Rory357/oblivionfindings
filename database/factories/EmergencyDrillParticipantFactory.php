<?php

namespace Database\Factories;

use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmergencyDrillParticipantFactory extends Factory
{
    protected $model = EmergencyDrillParticipant::class;

    public function definition(): array
    {
        return [
            'emergency_drill_id' => EmergencyDrill::factory(),
            'user_id' => User::factory(),
            'role' => fake()->randomElement(['participant', 'observer', 'warden', 'first_aider', 'coordinator']),
            'attended' => fake()->boolean(80),
        ];
    }
}
