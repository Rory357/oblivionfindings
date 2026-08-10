<?php

namespace Database\Factories;

use App\Models\Site;
use App\Support\NzRegions;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            // Compatibility value for the application's legacy storage column.
            // Site remains the real operational and authorisation boundary.
            'tenant_id' => 1,
            'name' => fake()->company().' '.fake()->randomElement(['Home', 'Care', 'Services']),
            'type' => fake()->randomElement(['head_office', 'house', 'facility', 'residential']),
            'address_line_1' => fake()->streetAddress(),
            'suburb' => fake()->city(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'region' => fake()->randomElement(NzRegions::REGIONS),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }
}
