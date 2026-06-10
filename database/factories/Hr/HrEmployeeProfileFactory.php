<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HrEmployeeProfileFactory extends Factory
{
    protected $model = HrEmployeeProfile::class;

    public function definition(): array
    {
        $role = fake()->randomElement(['support_worker', 'coordinator', 'team_lead', 'hr']);

        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'employee_number' => fake()->unique()->bothify('EMP-####'),
            'personal_email' => fake()->unique()->safeEmail(),
            'work_email' => fake()->unique()->companyEmail(),
            'work_phone' => fake()->phoneNumber(),
            'position_title' => ucwords(str_replace('_', ' ', $role)),
            'position_role' => $role,
            'employment_type' => fake()->randomElement(['full_time', 'part_time', 'casual']),
            'contract_type' => 'individual',
            'hours_per_week' => 40,
            'hourly_rate' => '30.00',
            'pay_frequency' => 'fortnightly',
            'start_date' => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'is_active' => true,
            'tax_code' => 'M',
            'kiwisaver_rate' => 3,
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
