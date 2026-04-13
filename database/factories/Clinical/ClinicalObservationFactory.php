<?php

namespace Database\Factories\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalObservation>
 */
class ClinicalObservationFactory extends Factory
{
    protected $model = ClinicalObservation::class;

    public function definition(): array
    {
        $type = fake()->randomElement(ObservationType::cases());

        return [
            'client_id' => Client::factory(),
            'recorded_by' => User::factory(),
            'observation_type' => $type,
            'recorded_at' => now(),
            'data' => $this->dataForType($type),
            'is_flagged' => false,
        ];
    }

    public function vitals(): static
    {
        return $this->state(fn () => [
            'observation_type' => ObservationType::Vitals,
            'data' => [
                'systolic' => fake()->numberBetween(100, 160),
                'diastolic' => fake()->numberBetween(60, 100),
                'pulse' => fake()->numberBetween(55, 100),
                'temperature' => fake()->randomFloat(1, 35.5, 38.5),
                'respiration_rate' => fake()->numberBetween(12, 22),
                'o2_saturation' => fake()->numberBetween(93, 100),
            ],
        ]);
    }

    public function weight(): static
    {
        return $this->state(fn () => [
            'observation_type' => ObservationType::Weight,
            'data' => [
                'weight_kg' => fake()->randomFloat(1, 40, 140),
            ],
        ]);
    }

    public function bowel(): static
    {
        return $this->state(fn () => [
            'observation_type' => ObservationType::Bowel,
            'data' => [
                'bristol_type' => fake()->numberBetween(1, 7),
                'notes' => fake()->optional()->sentence(),
            ],
        ]);
    }

    public function sleep(): static
    {
        return $this->state(fn () => [
            'observation_type' => ObservationType::Sleep,
            'data' => [
                'bed_time' => '21:30',
                'wake_time' => '07:00',
                'quality' => fake()->randomElement(['good', 'fair', 'poor']),
                'interruptions' => fake()->numberBetween(0, 5),
            ],
        ]);
    }

    public function fluidIntake(): static
    {
        return $this->state(fn () => [
            'observation_type' => ObservationType::FluidIntake,
            'data' => [
                'amount_ml' => fake()->randomElement([100, 150, 200, 250, 300, 500]),
                'fluid_type' => fake()->randomElement(['water', 'tea', 'juice', 'milk']),
            ],
        ]);
    }

    public function pain(): static
    {
        return $this->state(fn () => [
            'observation_type' => ObservationType::Pain,
            'data' => [
                'score' => fake()->numberBetween(0, 10),
                'location' => fake()->randomElement(['head', 'back', 'abdomen', 'legs', 'chest']),
            ],
        ]);
    }

    public function flagged(): static
    {
        return $this->state(fn () => [
            'is_flagged' => true,
            'flagged_reason' => fake()->sentence(),
            'flagged_by' => User::factory(),
        ]);
    }

    public function forShift(int $shiftId): static
    {
        return $this->state(fn () => [
            'shift_id' => $shiftId,
        ]);
    }

    private function dataForType(ObservationType $type): array
    {
        return match ($type) {
            ObservationType::Vitals => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
            ObservationType::Weight => ['weight_kg' => 70.5],
            ObservationType::Bowel => ['bristol_type' => 4],
            ObservationType::Sleep => ['bed_time' => '22:00', 'wake_time' => '07:00', 'quality' => 'good'],
            ObservationType::FluidIntake => ['amount_ml' => 250, 'fluid_type' => 'water'],
            ObservationType::Pain => ['score' => 3, 'location' => 'back'],
            ObservationType::General => ['notes' => 'General observation recorded.'],
        };
    }
}
