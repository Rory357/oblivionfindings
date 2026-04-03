<?php

namespace Database\Factories;

use App\Models\RespiteTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class RespiteTaskFactory extends Factory
{
    protected $model = RespiteTask::class;

    public function definition(): array
    {
        return [
            'subject_type' => 'App\\Models\\RespiteBooking',
            'subject_id' => 1,
            'title' => fake()->sentence(4),
            'status' => fake()->randomElement(['pending', 'in_progress', 'completed']),
        ];
    }
}
