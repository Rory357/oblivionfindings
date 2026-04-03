<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ServiceAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceAgreementFactory extends Factory
{
    protected $model = ServiceAgreement::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->sentence(4),
            'agreement_type' => fake()->randomElement(['residential', 'community', 'respite', 'day_programme']),
            'status' => fake()->randomElement(['draft', 'active', 'expired']),
            'starts_at' => fake()->date(),
        ];
    }
}
