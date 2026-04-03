<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinBankAccountFactory extends Factory
{
    protected $model = FinBankAccount::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'name' => fake()->words(3, true) . ' Account',
            'account_number' => fake()->unique()->numerify('##-####-#######-##'),
            'account_type' => fake()->randomElement(['cheque', 'savings', 'trust']),
            'is_active' => true,
            'current_balance' => fake()->randomFloat(2, 0, 500000),
        ];
    }
}
