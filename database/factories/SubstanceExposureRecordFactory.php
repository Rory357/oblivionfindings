<?php

namespace Database\Factories;

use App\Models\HazardousSubstance;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubstanceExposureRecordFactory extends Factory
{
    protected $model = SubstanceExposureRecord::class;

    public function definition(): array
    {
        return [
            'hazardous_substance_id' => HazardousSubstance::factory(),
            'user_id' => User::factory(),
            'site_id' => null,
            'exposed_at' => fake()->dateTimeBetween('-3 months', 'now'),
            'exposure_type' => fake()->randomElement(['inhalation', 'skin_contact', 'eye_contact', 'ingestion', 'injection', 'other']),
            'exposure_duration' => fake()->randomElement(['Brief', '5 minutes', '30 minutes']),
            'circumstances' => fake()->sentence(),
            'symptoms' => fake()->optional()->sentence(),
            'first_aid_given' => fake()->optional()->sentence(),
            'medical_treatment' => 'none',
            'medical_attention_sought' => false,
            'incident_reported' => false,
        ];
    }

    public function requiringMedicalAttention(): static
    {
        return $this->state(fn () => ['medical_treatment' => 'medical', 'medical_attention_sought' => true]);
    }

    /** Hospitalisation — a WorkSafe-notifiable exposure (HSWA 2015 s.23). */
    public function notifiable(): static
    {
        return $this->state(fn () => ['medical_treatment' => 'hospitalisation', 'medical_attention_sought' => true]);
    }
}
