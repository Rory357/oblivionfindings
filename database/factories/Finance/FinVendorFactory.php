<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinVendor;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinVendorFactory extends Factory
{
    protected $model = FinVendor::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'name' => fake()->company(),
            'vendor_type' => fake()->randomElement(['supplier', 'contractor', 'utility', 'government', 'other']),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
            'payment_terms_days' => fake()->randomElement([7, 14, 20, 30, 60]),
        ];
    }
}
