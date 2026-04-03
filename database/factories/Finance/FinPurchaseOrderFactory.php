<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinPurchaseOrderFactory extends Factory
{
    protected $model = FinPurchaseOrder::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'po_number' => fake()->unique()->bothify('PO-####'),
            'vendor_id' => FinVendor::factory(),
            'order_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'status' => fake()->randomElement(['draft', 'sent', 'received', 'cancelled']),
            'total_amount' => fake()->randomFloat(2, 100, 50000),
        ];
    }
}
