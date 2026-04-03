<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinJournal;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinJournalFactory extends Factory
{
    protected $model = FinJournal::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'journal_number' => fake()->unique()->bothify('JNL-####'),
            'journal_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'type' => fake()->randomElement(['standard', 'payroll', 'billing', 'adjustment', 'depreciation', 'opening', 'closing', 'recurring']),
            'status' => fake()->randomElement(['draft', 'posted', 'reversed']),
            'description' => fake()->sentence(),
        ];
    }
}
