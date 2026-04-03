<?php

namespace Database\Factories\Governance;

use App\Domain\Governance\Models\GovernanceMeeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GovernanceMeetingFactory extends Factory
{
    protected $model = GovernanceMeeting::class;

    public function definition(): array
    {
        return [
            'meeting_type' => fake()->randomElement(['board', 'committee', 'agm', 'sgm']),
            'title' => fake()->sentence(4),
            'scheduled_at' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'duration_minutes' => fake()->randomElement([60, 90, 120]),
            'location' => fake()->address(),
            'status' => 'scheduled',
            'quorum_required' => fake()->numberBetween(3, 7),
            'created_by' => User::factory(),
        ];
    }
}
