<?php

namespace Database\Factories;

use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimelineEvent>
 */
class TimelineEventFactory extends Factory
{
    protected $model = TimelineEvent::class;

    public function definition(): array
    {
        return [
            'source_type' => null,
            'source_id' => null,
            'occurred_at' => now(),
            'type' => 'timeline.note',
            'actor_user_id' => User::factory(),
            'client_id' => null,
            'shift_id' => null,
            'site_id' => null,
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'meta' => [],
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => null,
        ];
    }
}
