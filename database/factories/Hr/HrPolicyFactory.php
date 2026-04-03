<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrPolicyFactory extends Factory
{
    protected $model = HrPolicy::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->sentence(4),
            'slug' => fake()->unique()->slug(),
            'category' => fake()->randomElement(['health_safety', 'employment', 'leave', 'conduct', 'privacy', 'general']),
            'is_active' => true,
            'requires_attestation' => fake()->boolean(30),
        ];
    }
}
