<?php

namespace Database\Factories\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalProtocol>
 */
class ClinicalProtocolFactory extends Factory
{
    protected $model = ClinicalProtocol::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true) . ' monitoring',
            'observation_type' => fake()->randomElement(ObservationType::cases()),
            'frequency' => fake()->randomElement(ProtocolFrequency::cases()),
            'alert_if_missed_hours' => 24,
            'is_active' => true,
        ];
    }

    public function dailyWeight(): static
    {
        return $this->state(fn () => [
            'name' => 'Daily weight monitoring',
            'observation_type' => ObservationType::Weight,
            'frequency' => ProtocolFrequency::Daily,
        ]);
    }

    public function everyShiftVitals(): static
    {
        return $this->state(fn () => [
            'name' => 'Vital signs every shift',
            'observation_type' => ObservationType::Vitals,
            'frequency' => ProtocolFrequency::EveryShift,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withDateRange(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(3),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonths(6),
            'ends_at' => now()->subMonth(),
        ]);
    }
}
