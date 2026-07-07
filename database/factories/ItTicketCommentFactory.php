<?php

namespace Database\Factories;

use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItTicketComment>
 */
class ItTicketCommentFactory extends Factory
{
    protected $model = ItTicketComment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'ticket_id' => ItTicket::factory(),
            'author_user_id' => User::factory(),
            'body' => fake()->sentence(8),
            'is_internal' => false,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => ['is_internal' => true]);
    }
}
