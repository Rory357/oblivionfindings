<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinAccountFactory extends Factory
{
    protected $model = FinAccount::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(['asset', 'liability', 'equity', 'revenue', 'expense']),
            'is_active' => true,
            'is_system' => false,
            'opening_balance' => fake()->randomFloat(2, 0, 100000),
        ];
    }
}
