<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrExitInterview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrExitInterviewFactory extends Factory
{
    protected $model = HrExitInterview::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'employee_profile_id' => 1,
            'interviewer_user_id' => User::factory(),
            'interview_date' => fake()->dateTimeBetween('-1 month', '+1 month'),
            'departure_reason' => fake()->randomElement(['resignation', 'retirement', 'redundancy', 'end_of_contract', 'other']),
            'created_by' => User::factory(),
        ];
    }
}
