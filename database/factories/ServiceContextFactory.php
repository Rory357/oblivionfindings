<?php

namespace Database\Factories;

use App\Models\ServiceContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceContextFactory extends Factory
{
    protected $model = ServiceContext::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' ' . fake()->randomElement(['Home', 'Care', 'Services']),
            'type' => fake()->randomElement(['residential', 'home_support', 'planned_respite', 'group_home', 'community_participation', 'day_programme']),
            'is_active' => true,
        ];
    }
}
