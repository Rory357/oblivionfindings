<?php

namespace Database\Factories;

use App\Models\PriceBook;
use Illuminate\Database\Eloquent\Factories\Factory;

class PriceBookFactory extends Factory
{
    protected $model = PriceBook::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'is_active' => true,
            'effective_from' => fake()->date(),
        ];
    }
}
