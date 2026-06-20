<?php

namespace Database\Factories;

use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModifiedDutyFactory extends Factory
{
    protected $model = ModifiedDuty::class;

    public function definition(): array
    {
        return [
            'return_to_work_plan_id' => ReturnToWorkPlan::factory(),
            'user_id' => User::factory(),
            'start_date' => fake()->dateTimeBetween('-2 weeks', 'now'),
            'end_date' => null,
            'modified_duties_description' => fake()->sentence(),
            'restrictions' => fake()->optional()->sentence(),
            'accommodations' => fake()->optional()->sentence(),
            'hours_per_day' => fake()->randomElement([4, 6, 7.5]),
            'status' => 'active',
        ];
    }
}
