<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrLeaveRequestFactory extends Factory
{
    protected $model = HrLeaveRequest::class;

    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 week', '+3 months');
        $endsAt = (clone $startsAt)->modify('+' . fake()->numberBetween(1, 10) . ' days');

        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'leave_type' => fake()->randomElement(['annual', 'sick', 'bereavement', 'parental', 'public_holiday', 'unpaid']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'hours_requested' => fake()->numberBetween(4, 80),
            'status' => fake()->randomElement(['pending', 'approved', 'declined']),
            'reason' => fake()->sentence(),
        ];
    }
}
