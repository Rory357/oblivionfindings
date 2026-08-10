<?php

namespace Database\Factories;

use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItTicket>
 */
class ItTicketFactory extends Factory
{
    protected $model = ItTicket::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(10),
            'requester_user_id' => User::factory(),
            'category' => fake()->randomElement(ItTicket::CATEGORIES),
            'priority' => 'normal',
            'impact' => 'individual',
            'urgency' => 'normal',
            'status' => 'open',
            'source' => 'portal',
            'work_type' => 'incident',
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['priority' => 'urgent']);
    }
}
