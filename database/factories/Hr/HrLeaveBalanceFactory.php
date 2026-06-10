<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrLeaveBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrLeaveBalanceFactory extends Factory
{
    protected $model = HrLeaveBalance::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'leave_type' => 'annual',
            'balance_hours' => 152,
            'accrued_hours' => 152,
            'used_hours' => 0,
            'pending_hours' => 0,
            'year' => now()->year,
            'source' => 'system',
            'last_synced_at' => now(),
            'updated_by' => User::factory(),
        ];
    }
}
