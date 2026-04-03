<?php

namespace Database\Factories;

use App\Models\DataSubjectRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class DataSubjectRequestFactory extends Factory
{
    protected $model = DataSubjectRequest::class;

    public function definition(): array
    {
        return [
            'reference_number' => 'DSR-' . fake()->unique()->numerify('####'),
            'request_type' => fake()->randomElement(['access', 'rectification', 'erasure', 'portability', 'restriction', 'objection']),
            'request_details' => fake()->paragraph(),
            'received_at' => fake()->dateTimeBetween('-14 days', 'now'),
            'due_date' => fake()->dateTimeBetween('now', '+20 days'),
            'status' => fake()->randomElement(['pending', 'in_progress', 'completed']),
        ];
    }
}
