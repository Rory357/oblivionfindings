<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * First Aid Register gold-standard rebuild — test factory.
 *
 * Aligned to FirstAidRecord::$fillable and the canonical enums in
 * StoreFirstAidRecordRequest (injury_illness_type / treatment_outcome) so a freshly
 * made record always passes the FormRequest. Defaults to a staff treatment with no
 * client link; use client() for a client treatment and ambulance() for the escalation
 * signal that drives the "reportable" tab/hero.
 *
 * @extends Factory<FirstAidRecord>
 */
class FirstAidRecordFactory extends Factory
{
    protected $model = FirstAidRecord::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            // Defaults to a staff treatment captured by name; treated_person_id stays null
            // (the optional "treated staff" picker links it). client() sets the client path.
            'treated_person_id' => null,
            'client_id' => null,
            'treated_person_name' => fake()->name(),
            'treated_person_type' => 'staff',
            'treatment_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'injury_illness_type' => fake()->randomElement(['cut', 'burn', 'sprain', 'fracture', 'fall', 'other']),
            'injury_illness_description' => fake()->sentence(),
            'body_part' => fake()->randomElement(['Left hand', 'Right knee', 'Forehead', 'Lower back', 'Right ankle', 'Left wrist']),
            'treatment_given' => fake()->sentence(),
            'treatment_outcome' => fake()->randomElement([
                'returned_to_activity', 'sent_home', 'medical_centre', 'sent_to_hospital', 'ongoing_monitoring',
            ]),
            'ambulance_called' => false,
            'first_aider_id' => User::factory(),
            'incident_reported' => false,
            'related_incident_id' => null,
        ];
    }

    /** An ambulance was called — the escalation signal that lands the record on the "ambulance"/"reportable" surfaces. */
    public function ambulance(): static
    {
        return $this->state(fn () => [
            'ambulance_called' => true,
            'treatment_outcome' => 'sent_to_hospital',
        ]);
    }

    /** A client treatment (treated_person_type=client + a real client link), which can auto-create an incident. */
    public function client(): static
    {
        return $this->state(fn () => [
            'treated_person_type' => 'client',
            'client_id' => Client::factory(),
        ]);
    }
}
