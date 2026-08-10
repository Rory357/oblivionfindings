<?php

namespace Database\Factories;

use App\Models\ItCatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItCatalogItem>
 */
class ItCatalogItemFactory extends Factory
{
    protected $model = ItCatalogItem::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(4, true);

        return [
            'tenant_id' => 1,
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'outcome_type' => 'service_request',
            'category' => 'other',
            'default_priority' => 'normal',
            'requires_approval' => false,
            'is_published' => true,
            'internal_only' => false,
            'form_schema_version' => 1,
            'form_schema' => ['fields' => []],
            'search_terms' => [],
            'sort_order' => 0,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    public function securityRequest(): static
    {
        return $this->state(fn () => ['outcome_type' => 'security_request']);
    }

    public function provisioning(): static
    {
        return $this->state(fn () => [
            'outcome_type' => 'provisioning',
            'provisioning_type' => 'account',
        ]);
    }
}
