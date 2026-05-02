<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            // Default to the single-tenant value used across the test suite.
            // Tests that need a different tenant override this explicitly;
            // many SD/UniFi tests scope `Site::query()->where('tenant_id', 1)`
            // and would silently see zero rows without this default.
            'tenant_id' => 1,
            'name' => fake()->company() . ' ' . fake()->randomElement(['Home', 'Care', 'Services']),
            'type' => fake()->randomElement(['head_office', 'house', 'facility', 'residential']),
            'address_line_1' => fake()->streetAddress(),
            'suburb' => fake()->city(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }
}
