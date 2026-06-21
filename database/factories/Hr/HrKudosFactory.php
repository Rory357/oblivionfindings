<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrKudos;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrKudosFactory extends Factory
{
    protected $model = HrKudos::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'category' => fake()->randomElement([
                'teamwork', 'innovation', 'leadership', 'customer_focus', 'going_above', 'other',
            ]),
            'message' => fake()->sentence(12),
            'is_public' => true,
        ];
    }
}
