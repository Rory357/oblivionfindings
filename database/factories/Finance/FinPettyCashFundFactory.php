<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinPettyCashFund;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinPettyCashFundFactory extends Factory
{
    protected $model = FinPettyCashFund::class;

    public function definition(): array
    {
        $floatAmount = fake()->randomFloat(2, 100, 1000);

        return [
            'organization_id' => 1,
            'name' => fake()->words(2, true) . ' Petty Cash',
            'float_amount' => $floatAmount,
            'current_balance' => fake()->randomFloat(2, 0, $floatAmount),
            'is_active' => true,
        ];
    }
}
