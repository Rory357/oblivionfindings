<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinCashFlowForecast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinCashFlowForecastFactory extends Factory
{
    protected $model = FinCashFlowForecast::class;

    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('now', '+1 month');
        $periodEnd = (clone $periodStart)->modify('+3 months');

        return [
            'organization_id' => 1,
            'name' => fake()->sentence(3),
            'forecast_date' => fake()->dateTimeBetween('-1 week', 'now'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_type' => fake()->randomElement(['weekly', 'fortnightly', 'monthly']),
            'opening_balance' => fake()->randomFloat(2, 10000, 500000),
            'forecast_data' => json_encode([]),
            'status' => fake()->randomElement(['draft', 'final']),
            'created_by' => User::factory(),
        ];
    }
}
