<?php

namespace Database\Factories;

use App\Models\HsTrainingRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HsTrainingRequirementFactory extends Factory
{
    protected $model = HsTrainingRequirement::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true) . ' Training',
            'code' => 'HS_' . strtoupper(fake()->unique()->lexify('???_???')),
            'scope_type' => HsTrainingRequirement::SCOPE_GLOBAL,
            'enforcement_mode' => HsTrainingRequirement::ENFORCEMENT_WARN,
            'grace_period_days' => 30,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function blocking(): static
    {
        return $this->state(fn () => [
            'enforcement_mode' => HsTrainingRequirement::ENFORCEMENT_BLOCK,
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn () => [
            'enforcement_mode' => HsTrainingRequirement::ENFORCEMENT_WARN,
        ]);
    }

    public function forRole(string $role): static
    {
        return $this->state(fn () => [
            'scope_type' => HsTrainingRequirement::SCOPE_ROLE,
            'scope_roles' => [$role],
        ]);
    }

    public function forSite(int $siteId): static
    {
        return $this->state(fn () => [
            'scope_type' => HsTrainingRequirement::SCOPE_SITE,
            'scope_site_ids' => [$siteId],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
