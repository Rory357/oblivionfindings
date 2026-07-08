<?php

namespace Database\Factories;

use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItKbArticle>
 */
class ItKbArticleFactory extends Factory
{
    protected $model = ItKbArticle::class;

    public function definition(): array
    {
        $title = rtrim($this->faker->unique()->sentence(4), '.');

        return [
            'tenant_id' => 1,
            'title' => $title,
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'category' => $this->faker->randomElement(ItTicket::CATEGORIES),
            'body' => $this->faker->paragraphs(2, true),
            'status' => 'draft',
            'author_user_id' => User::factory(),
            'view_count' => 0,
            'helpful_yes' => 0,
            'helpful_no' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published']);
    }
}
