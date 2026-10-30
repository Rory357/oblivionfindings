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
            // Relative to Carbon's now() so a frozen or travelled test clock
            // applies: Faker's dateTimeBetween reads the real clock, which
            // could start the profile after a test's fixed past "now".
            'start_date' => now()->subDays(fake()->numberBetween(30, 1095))->toDateString(),
            'is_active' => true,
            'tax_code' => 'M',
            'kiwisaver_rate' => 3,
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
