<?php

namespace Database\Factories\Finance;

use App\Domain\Finance\Models\FinFixedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinFixedAssetFactory extends Factory
{
    protected $model = FinFixedAsset::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'asset_name' => fake()->words(3, true),
            'category' => fake()->randomElement(['vehicle', 'equipment', 'furniture', 'it_equipment', 'building', 'land']),
            'purchase_date' => fake()->dateTimeBetween('-5 years', 'now'),
            'purchase_cost' => fake()->randomFloat(2, 500, 200000),
            'useful_life_months' => fake()->randomElement([12, 24, 36, 60, 120, 240]),
            'depreciation_method' => fake()->randomElement(['straight_line', 'diminishing_value']),
            'status' => fake()->randomElement(['active', 'disposed', 'fully_depreciated']),
        ];
    }
}
