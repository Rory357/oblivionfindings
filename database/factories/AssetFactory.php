<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->words(3, true),
            'asset_tag' => 'AST-' . fake()->unique()->numerify('####'),
            'qr_token' => Str::uuid()->toString(),
            'category' => fake()->randomElement(['IT Equipment', 'Medical Device', 'Furniture', 'Vehicle', 'Safety Equipment']),
            'description' => fake()->sentence(),
            'manufacturer' => fake()->company(),
            'model' => fake()->word(),
            'serial_number' => fake()->unique()->numerify('SN-########'),
            'status' => 'active',
            'risk_level' => 'low',
            'location' => fake()->randomElement(['Office', 'Store Room', 'Kitchen', 'Lounge', 'Bedroom 1']),
            'requires_inspection' => false,
            'requires_maintenance' => false,
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function outOfService(): static
    {
        return $this->state(fn () => ['status' => 'out_of_service']);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => 'retired']);
    }

    public function highRisk(): static
    {
        return $this->state(fn () => ['risk_level' => 'high']);
    }

    public function mediumRisk(): static
    {
        return $this->state(fn () => ['risk_level' => 'medium']);
    }

    public function forSite(Site $site): static
    {
        return $this->state(fn () => ['site_id' => $site->id]);
    }

    public function forClient(int $clientId): static
    {
        return $this->state(fn () => ['client_id' => $clientId]);
    }

    public function requiresInspection(): static
    {
        return $this->state(fn () => [
            'requires_inspection' => true,
            'inspection_due_at' => now()->addDays(30),
        ]);
    }

    public function requiresMaintenance(): static
    {
        return $this->state(fn () => [
            'requires_maintenance' => true,
            'maintenance_due_at' => now()->addDays(60),
        ]);
    }

    public function vehicle(): static
    {
        return $this->state(fn () => [
            'category' => 'Vehicle',
            'manufacturer' => fake()->randomElement(['Toyota', 'Ford', 'Nissan', 'Honda']),
        ]);
    }
}
