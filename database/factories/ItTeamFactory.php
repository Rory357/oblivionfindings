<?php

namespace Database\Factories;

use App\Models\ItTeam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItTeam>
 */
class ItTeamFactory extends Factory
{
    protected $model = ItTeam::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Service Desk',
            'Infrastructure',
            'Applications',
            'Security Operations',
            'Field Services',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'tenant_id' => 1,
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
