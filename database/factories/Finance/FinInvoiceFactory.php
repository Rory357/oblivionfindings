<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinInvoiceFactory extends Factory
{
    protected $model = FinInvoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 50000);

        return [
            'organization_id' => 1,
            'invoice_number' => fake()->unique()->bothify('INV-####'),
            'invoice_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'due_date' => fake()->dateTimeBetween('now', '+3 months'),
            'client_name' => fake()->company(),
            'status' => fake()->randomElement(['draft', 'sent', 'viewed', 'paid', 'overdue', 'cancelled']),
            'subtotal' => $subtotal,
            'total_amount' => round($subtotal * 1.15, 2),
        ];
    }
}
