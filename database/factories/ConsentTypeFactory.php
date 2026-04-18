<?php

namespace Database\Factories;

use App\Models\ConsentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentType>
 */
class ConsentTypeFactory extends Factory
{
    protected $model = ConsentType::class;

    public function definition(): array
    {
        return [
            'name' => 'Asset Location Tracking (Safety)',
            'category' => 'safety',
            'description' => 'Consent to location monitoring of a personal tracker for safety.',
            'purpose' => 'Enable care team to locate the client in an emergency.',
            'legal_basis' => 'HDC Code of Rights Right 7; NZ Privacy Act 2020 IPP1-3; PPPR Act 1988 where applicable.',
            'is_mandatory' => false,
            'requires_capacity_assessment' => true,
            'allows_withdrawal' => true,
            'withdrawal_notice_days' => 7,
            'validity_period_days' => 365,
            'renewal_required' => true,
            'renewal_reminder_days' => 30,
            'version' => 1,
            'active' => true,
        ];
    }

    public function forSafety(): static
    {
        return $this->state(fn () => ['category' => 'safety']);
    }
}
