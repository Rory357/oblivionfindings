<?php

namespace Database\Factories;

use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterTemplateFactory extends Factory
{
    protected $model = RosterTemplate::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'name' => fake()->words(3, true).' template',
            'description' => fake()->optional()->sentence(),
            'template_type' => 'weekly',
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function withShifts(int $count = 1, array $state = []): static
    {
        return $this->has(
            RosterTemplateShift::factory()->count($count)->state($state),
            'templateShifts',
        );
    }
}
