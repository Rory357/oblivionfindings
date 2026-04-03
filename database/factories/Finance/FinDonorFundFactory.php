<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinDonorFund;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinDonorFundFactory extends Factory
{
    protected $model = FinDonorFund::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'fund_code' => fake()->unique()->bothify('FUND-####'),
            'fund_name' => fake()->words(3, true) . ' Fund',
            'fund_type' => fake()->randomElement(['grant', 'donation', 'bequest', 'trust', 'government']),
            'status' => fake()->randomElement(['active', 'fully_spent', 'expired', 'returned']),
            'is_restricted' => fake()->boolean(40),
        ];
    }
}
