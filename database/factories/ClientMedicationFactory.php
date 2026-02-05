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
        $isPrn = fake()->boolean(30);
        
        return [
            'client_id' => Client::factory(),
            'name' => fake()->randomElement(['Paracetamol', 'Ibuprofen', 'Aspirin', 'Metformin', 'Omeprazole']),
            'generic_name' => fake()->optional()->word(),
            'dosage' => fake()->randomElement(['500mg', '1000mg', '10mg', '20mg']),
            'route' => fake()->randomElement(['oral', 'sublingual', 'topical', 'inhalation']),
            'frequency' => $isPrn ? null : fake()->randomElement(['Once daily', 'Twice daily', 'Three times daily']),
            'prescribed_time' => $isPrn ? null : fake()->time('H:i'),
            'is_prn' => $isPrn,
            'prn_reason' => $isPrn ? fake()->sentence() : null,
            'instructions' => fake()->optional()->paragraph(),
            'prescriber' => fake()->name(),
            'prescribed_date' => fake()->date(),
            'is_controlled' => fake()->boolean(10),
            'stock_count' => fake()->optional()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
