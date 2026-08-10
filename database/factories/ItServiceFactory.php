<?php

namespace Database\Factories;

use App\Models\ItService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItService>
 */
class ItServiceFactory extends Factory
{
    protected $model = ItService::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Site connectivity',
            'Identity and access',
            'Microsoft 365',
            'Clinical device connectivity',
            'Physical security',
        ]).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'tenant_id' => 1,
            'key' => Str::slug($name),
            'name' => $name,
            'description' => fake()->sentence(),
            'status' => 'operational',
            'criticality' => 'medium',
            'is_active' => true,
        ];
    }
}
