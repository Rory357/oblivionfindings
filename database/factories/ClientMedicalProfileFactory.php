<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientMedicalProfileFactory extends Factory
{
    protected $model = ClientMedicalProfile::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'medical_history' => fake()->optional()->paragraph(),
            'disabilities' => fake()->optional()->paragraph(),
            'allergies' => fake()->optional()->paragraph(),
            'notes' => fake()->optional()->paragraph(),
        ];
    }
}
