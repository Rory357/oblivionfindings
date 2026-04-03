<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrExpenseClaimFactory extends Factory
{
    protected $model = HrExpenseClaim::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'claim_number' => fake()->unique()->bothify('EXP-####'),
            'title' => fake()->sentence(3),
            'status' => fake()->randomElement(['draft', 'submitted', 'approved', 'rejected', 'paid']),
            'total_amount' => fake()->randomFloat(2, 10, 5000),
            'currency' => 'NZD',
        ];
    }
}
