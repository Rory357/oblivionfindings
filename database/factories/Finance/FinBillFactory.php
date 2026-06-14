<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinVendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinBillFactory extends Factory
{
    protected $model = FinBill::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'vendor_id' => FinVendor::factory(),
            'bill_number' => fake()->unique()->bothify('BILL-####'),
            'bill_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'due_date' => fake()->dateTimeBetween('now', '+3 months'),
            // Must match the fin_bills.status enum — 'void' is not a member and
            // randomly truncated on insert (flaky factory failures).
            'status' => fake()->randomElement(['draft', 'awaiting_approval', 'approved', 'paid', 'cancelled']),
            'total_amount' => fake()->randomFloat(2, 50, 25000),
        ];
    }
}
