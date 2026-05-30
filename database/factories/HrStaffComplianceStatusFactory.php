<?php

namespace Database\Factories;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrStaffComplianceStatusFactory extends Factory
{
    protected $model = HrStaffComplianceStatus::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'requirement_id' => HrComplianceRequirement::factory(),
            'status' => 'compliant',
            'evidence_type' => 'training_record',
            'valid_from' => now()->subMonths(6),
            'expires_at' => now()->addMonths(6),
            'last_checked_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'expires_at' => now()->subDays(10),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => [
            'status' => 'expiring_soon',
            'expires_at' => now()->addDays(15),
        ]);
    }
}
