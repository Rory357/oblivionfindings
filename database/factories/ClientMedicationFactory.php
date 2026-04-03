<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientMedication;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientMedicationFactory extends Factory
{
    protected $model = ClientMedication::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->randomElement(['Paracetamol', 'Ibuprofen', 'Aspirin', 'Metformin', 'Omeprazole']),
            'dosage' => fake()->randomElement(['500mg', '1000mg', '10mg', '20mg']),
            'frequency' => fake()->randomElement(['Once daily', 'Twice daily', 'Three times daily']),
            'route' => fake()->randomElement(['oral', 'sublingual', 'topical', 'inhalation']),
            'prescriber' => fake()->name(),
            'start_date' => fake()->date(),
            'end_date' => fake()->optional(0.3)->date(),
            'instructions' => fake()->optional()->paragraph(),
        ];
    }
}
