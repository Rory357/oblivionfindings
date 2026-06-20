<?php

namespace Database\Factories;

use App\Models\PpeType;
use Illuminate\Database\Eloquent\Factories\Factory;

class PpeTypeFactory extends Factory
{
    protected $model = PpeType::class;

    public function definition(): array
    {
        $category = fake()->randomElement([
            'head', 'eye', 'ear', 'respiratory', 'hand', 'foot', 'high_visibility', 'fall_protection',
        ]);

        $standards = [
            'head' => 'AS/NZS 1801',
            'eye' => 'AS/NZS 1337.1',
            'ear' => 'AS/NZS 1270',
            'respiratory' => 'AS/NZS 1715 & 1716',
            'hand' => 'AS/NZS 2161',
            'foot' => 'AS/NZS 2210.3',
            'high_visibility' => 'AS/NZS 4602.1',
            'fall_protection' => 'AS/NZS 1891.1',
        ];

        return [
            'name' => fake()->unique()->words(2, true),
            'category' => $category,
            'description' => fake()->sentence(),
            'hazards_addressed' => fake()->sentence(),
            'standards_reference' => $standards[$category] ?? null,
            'inspection_frequency' => fake()->randomElement(['daily', 'weekly', 'monthly', 'quarterly', 'annually']),
            'typical_lifespan_months' => fake()->numberBetween(12, 60),
            'is_active' => true,
        ];
    }

    public function respiratory(): static
    {
        return $this->state(fn () => [
            'category' => 'respiratory',
            'standards_reference' => 'AS/NZS 1715 & 1716',
            'inspection_frequency' => 'monthly',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
