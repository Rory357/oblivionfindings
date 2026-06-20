<?php

namespace Database\Factories;

use App\Models\HazardousSubstance;
use App\Models\Site;
use App\Models\SubstanceStorageLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubstanceStorageLocationFactory extends Factory
{
    protected $model = SubstanceStorageLocation::class;

    public function definition(): array
    {
        $max = fake()->randomElement([10, 20, 50]);

        return [
            'hazardous_substance_id' => HazardousSubstance::factory(),
            'site_id' => Site::factory(),
            'location_description' => fake()->randomElement(['Chemical store, bay 1', 'Clinical room cabinet', 'Flammables cabinet']),
            'current_quantity' => fake()->numberBetween(1, $max),
            'quantity_unit' => fake()->randomElement(['L', 'kg', 'units']),
            'maximum_quantity' => $max,
            'container_type' => fake()->randomElement(['HDPE drum', '5 L bottles', 'Sealed pail']),
            'properly_labelled' => true,
            'segregation_compliant' => true,
            'last_audit_date' => fake()->dateTimeBetween('-3 months', 'now'),
        ];
    }

    public function nonCompliant(): static
    {
        return $this->state(fn () => ['segregation_compliant' => false]);
    }
}
