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
            'nhi_number' => fake()->regexify('[A-Z]{3}[0-9]{4}'),
            'blood_type' => fake()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
            'allergies' => fake()->optional()->paragraph(),
            'dietary_requirements' => fake()->optional()->paragraph(),
            'mobility_notes' => fake()->optional()->paragraph(),
            'communication_notes' => fake()->optional()->paragraph(),
        ];
    }
}
