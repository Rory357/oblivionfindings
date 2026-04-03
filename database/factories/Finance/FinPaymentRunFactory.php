<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinPaymentRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinPaymentRunFactory extends Factory
{
    protected $model = FinPaymentRun::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'run_number' => fake()->unique()->bothify('PAY-####'),
            'payment_date' => fake()->dateTimeBetween('-1 month', '+1 month'),
            'status' => fake()->randomElement(['draft', 'approved', 'processing', 'completed']),
            'total_amount' => fake()->randomFloat(2, 1000, 100000),
        ];
    }
}
