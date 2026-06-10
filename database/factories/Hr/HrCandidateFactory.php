<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrCandidateFactory extends Factory
{
    protected $model = HrCandidate::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['new', 'screening', 'interview_scheduled', 'reference_check', 'offer_pending']);

        return [
            'tenant_id' => 1,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'personal_email' => fake()->unique()->safeEmail(),
            'personal_phone' => fake()->phoneNumber(),
            'source' => fake()->randomElement(['website', 'referral', 'agency', 'internal', 'other']),
            'source_detail' => fake()->optional()->sentence(),
            'status' => $status,
            'current_stage_entered_at' => now()->subDays(fake()->numberBetween(1, 14)),
            'privacy_consent_given_at' => now()->subDays(fake()->numberBetween(1, 30)),
            'privacy_consent_ip' => fake()->ipv4(),
            'notes' => fake()->optional()->sentence(),
            'tags' => fake()->randomElements(['care', 'night-shift', 'driver', 'experienced'], 2),
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
