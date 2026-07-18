<?php

namespace Database\Factories;

use App\Models\ItQueue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItQueue>
 */
class ItQueueFactory extends Factory
{
    protected $model = ItQueue::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'tenant_id' => 1,
            'key' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'filter_rules' => ['status' => ['open', 'in_progress']],
            'is_active' => true,
        ];
    }
}
