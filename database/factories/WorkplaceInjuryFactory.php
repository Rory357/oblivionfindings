<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkplaceInjuryFactory extends Factory
{
    protected $model = WorkplaceInjury::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'site_id' => Site::factory(),
            'related_incident_id' => null,
            'injury_date' => fake()->dateTimeBetween('-6 months', 'now'),
            // Canonical injury_type enum (matches ReturnToWorkController@store).
            'injury_type' => fake()->randomElement([
                'strain', 'laceration', 'fracture', 'burn', 'contusion', 'concussion',
                'repetitive_strain', 'chemical_exposure', 'biological_exposure', 'needle_stick',
                'slip_trip_fall', 'manual_handling', 'psychological', 'illness', 'other',
            ]),
            'body_part_affected' => fake()->randomElement(['Lower back', 'Right hand', 'Left ankle', 'Right wrist', 'Shoulder', 'Both eyes']),
            'severity' => fake()->randomElement(['minor', 'moderate', 'serious', 'critical']),
            'description' => fake()->paragraph(),
            'immediate_treatment' => fake()->sentence(),
            'medical_treatment_type' => fake()->randomElement(['none', 'first_aid', 'gp_visit', 'hospital', 'emergency_department', 'hospitalisation', 'specialist', 'ongoing']),
            'worksafe_notifiable' => false,
            'acc_claim_lodged' => false,
            'acc_claim_number' => null,
            'lost_time_days' => 0,
            'status' => 'reported',
        ];
    }

    public function worksafeNotifiable(): static
    {
        return $this->state(fn () => ['worksafe_notifiable' => true, 'severity' => 'serious']);
    }

    public function accLodged(): static
    {
        return $this->state(fn () => [
            'acc_claim_lodged' => true,
            'acc_claim_number' => '26/'.fake()->numberBetween(100000, 999999),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
