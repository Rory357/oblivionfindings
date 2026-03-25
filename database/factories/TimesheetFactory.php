<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimesheetFactory extends Factory
{
    protected $model = Timesheet::class;

    public function definition(): array
    {
        $workDate = fake()->dateTimeBetween('-1 month', 'now');
        $startTime = (clone $workDate)->setTime(9, 0);
        $endTime = (clone $workDate)->setTime(17, 0);

        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'shift_id' => Shift::factory(),
            'work_date' => $workDate,
            'starts_at' => $startTime,
            'ends_at' => $endTime,
            'break_minutes' => fake()->randomElement([0, 30, 60]),
            'mileage_km' => 0,
            'sleepover' => false,
            'on_call' => false,
            'allowance_notes' => null,
            'public_holiday' => false,
            'notes' => fake()->optional()->paragraph(),
            'is_residential_billable' => false,
            'status' => 'draft',
            'submitted_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }
}
