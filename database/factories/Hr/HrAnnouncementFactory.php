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
            'target_audience' => fake()->randomElement(['all', 'managers', 'department', 'team']),
        ];
    }
}
