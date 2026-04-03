<?php

namespace Database\Factories;

use App\Models\DataRetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

class DataRetentionPolicyFactory extends Factory
{
    protected $model = DataRetentionPolicy::class;

    public function definition(): array
    {
        return [
            'model_type' => fake()->randomElement(['App\\Models\\Client', 'App\\Models\\User', 'App\\Models\\Document']),
            'policy_name' => fake()->sentence(3),
            'retention_period_years' => fake()->randomElement([1, 2, 5, 7]),
            'active' => true,
        ];
    }
}
