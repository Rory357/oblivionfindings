<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrAnnouncementFactory extends Factory
{
    protected $model = HrAnnouncement::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => fake()->sentence(5),
            'content' => fake()->paragraphs(3, true),
            'created_by' => User::factory(),
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'status' => 'published',
            'target_audience' => fake()->randomElement(['all', 'department', 'site', 'role']),
            'target_value' => null,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'published_at' => null]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => ['status' => 'scheduled', 'published_at' => now()->addDays(2)]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => 'archived']);
    }

    public function requiresAck(): static
    {
        return $this->state(fn () => ['requires_acknowledgement' => true]);
    }
}
