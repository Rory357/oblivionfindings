<?php

namespace Database\Factories\Governance;

use App\Domain\Governance\Models\GovernancePolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GovernancePolicyFactory extends Factory
{
    protected $model = GovernancePolicy::class;

    public function definition(): array
    {
        return [
            'policy_code' => 'POL-' . fake()->unique()->numerify('###'),
            'title' => fake()->sentence(4),
            'category' => fake()->randomElement(['governance', 'clinical', 'operational', 'hr', 'financial', 'health_safety']),
            'content' => fake()->paragraphs(3, true),
            'version_number' => 1,
            'status' => 'draft',
            'owner_id' => User::factory(),
            'created_by' => User::factory(),
        ];
    }
}
