<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinCreditNote;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinCreditNoteFactory extends Factory
{
    protected $model = FinCreditNote::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'credit_note_number' => fake()->unique()->bothify('CN-####'),
            'type' => fake()->randomElement(['payable', 'receivable']),
            'credit_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'status' => fake()->randomElement(['draft', 'approved', 'applied', 'cancelled']),
            'total_amount' => fake()->randomFloat(2, 50, 10000),
        ];
    }
}
