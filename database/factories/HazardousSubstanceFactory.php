<?php

namespace Database\Factories;

use App\Models\HazardousSubstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class HazardousSubstanceFactory extends Factory
{
    protected $model = HazardousSubstance::class;

    public function definition(): array
    {
        $form = fake()->randomElement(['solid', 'liquid', 'gas', 'powder', 'aerosol']);

        return [
            'name' => ucfirst(fake()->words(2, true)),
            'common_name' => fake()->optional()->word(),
            'un_number' => fake()->optional()->numerify('UN####'),
            'hsno_classification' => fake()->randomElement(['3.1B Flammable liquid', '8.2A Corrosive', '6.1C Toxic', '5.1.1A Oxidising', '2.1.1A Flammable gas']),
            'hazard_classifications' => fake()->randomElements(['Flammable', 'Corrosive', 'Toxic', 'Oxidising'], fake()->numberBetween(1, 2)),
            'ghs_pictograms' => fake()->randomElements(['GHS02', 'GHS05', 'GHS06', 'GHS03'], fake()->numberBetween(1, 2)),
            'signal_word' => fake()->randomElement(['Danger', 'Warning']),
            'physical_form' => $form,
            'ppe_required' => 'Gloves, eye protection.',
            'storage_requirements' => 'Store in a cool, ventilated area.',
            'requires_tracking' => false,
            'is_controlled_substance' => false,
            'status' => 'active',
        ];
    }

    public function controlled(): static
    {
        return $this->state(fn () => ['is_controlled_substance' => true, 'requires_tracking' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}
