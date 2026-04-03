<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinGstReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinGstReturnFactory extends Factory
{
    protected $model = FinGstReturn::class;

    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-6 months', '-1 month');
        $periodEnd = (clone $periodStart)->modify('+2 months');

        return [
            'organization_id' => 1,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'filing_frequency' => fake()->randomElement(['monthly', 'two_monthly', 'six_monthly']),
            'basis' => fake()->randomElement(['invoice', 'payments', 'hybrid']),
            'status' => fake()->randomElement(['draft', 'filed', 'amended']),
        ];
    }
}
