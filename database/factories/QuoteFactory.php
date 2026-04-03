<?php

namespace Database\Factories;

use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'quote_number' => 'QTE-' . fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'status' => fake()->randomElement(['draft', 'sent', 'accepted', 'declined']),
            'total_amount' => fake()->randomFloat(2, 500, 50000),
            'valid_until' => fake()->dateTimeBetween('+7 days', '+60 days'),
        ];
    }
}
