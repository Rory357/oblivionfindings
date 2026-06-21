<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrKudosReactionFactory extends Factory
{
    protected $model = HrKudosReaction::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'kudos_id' => HrKudos::factory(),
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(['heart', 'party', 'hands']),
        ];
    }
}
