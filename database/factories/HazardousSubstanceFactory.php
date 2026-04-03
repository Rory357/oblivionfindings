<?php

namespace Database\Factories;

use App\Models\HazardousSubstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class HazardousSubstanceFactory extends Factory
{
    protected $model = HazardousSubstance::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
        ];
    }
}
