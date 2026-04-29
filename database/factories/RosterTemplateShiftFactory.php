<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\ServiceContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterTemplateShiftFactory extends Factory
{
    protected $model = RosterTemplateShift::class;

    public function definition(): array
    {
        return [
            'organization_id' => 1,
            'roster_template_id' => RosterTemplate::factory(),
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'service_context_id' => ServiceContext::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00',
            'end_time' => '13:00',
            'shift_type' => 'standard',
            'is_sleepover' => false,
            'is_on_call' => false,
            'expected_break_minutes' => null,
            'required_skills' => [],
            'location' => fake()->optional()->streetAddress(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
        ]);
    }
}
