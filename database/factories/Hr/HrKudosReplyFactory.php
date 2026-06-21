<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrKudosReplyFactory extends Factory
{
    protected $model = HrKudosReply::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'kudos_id' => HrKudos::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(10),
        ];
    }
}
