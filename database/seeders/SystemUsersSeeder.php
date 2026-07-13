<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemUsersSeeder extends Seeder
{
    private const DEFAULT_TENANT_ID = 1;

    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            [
                'email' => 'admin@demo.test',
                'name' => 'Demo Admin',
                'role' => 'admin',
                'staff_data' => ['job_title' => 'System Administrator', 'department' => 'IT'],
            ],
            [
                'email' => 'manager@demo.test',
                'name' => 'Demo Manager',
                'role' => 'provider_manager',
                'staff_data' => ['job_title' => 'Provider Manager', 'department' => 'Operations'],
            ],
            [
                'email' => 'coord@demo.test',
                'name' => 'Demo Coordinator',
                'role' => 'coordinator',
                'staff_data' => ['job_title' => 'Care Coordinator', 'department' => 'Clinical'],
            ],
            [
                'email' => 'finance@demo.test',
                'name' => 'Demo Finance',
                'role' => 'finance',
                'staff_data' => ['job_title' => 'Finance Officer', 'department' => 'Finance'],
            ],
            [
                'email' => 'hr@demo.test',
                'name' => 'Demo HR',
                'role' => 'hr',
                'staff_data' => ['job_title' => 'HR Manager', 'department' => 'HR'],
            ],
            [
                'email' => 'auditor@demo.test',
                'name' => 'Demo Auditor',
                'role' => 'auditor',
                'staff_data' => ['job_title' => 'Internal Auditor', 'department' => 'Compliance'],
            ],
        ];

        foreach ($users as $u) {
            $user = User::query()->firstOrNew(['email' => $u['email']]);
            $user->forceFill([
                'name' => $u['name'],
                'password' => $password,
                'role' => $u['role'],
                'approved_at' => now(),
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $role = Role::query()->where('name', $u['role'])->first();
            if ($role) {
                $user->roles()->sync([$role->id]);
            }

            // Create staff record for staff users
            if (!empty($u['staff_data'])) {
                $staff = Staff::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_id' => strtoupper(substr($u['role'], 0, 3)) . str_pad($user->id, 3, '0', STR_PAD_LEFT),
                        'job_title' => $u['staff_data']['job_title'],
                        'department' => $u['staff_data']['department'],
                        'status' => 'active',
                        'hire_date' => now()->subYears(rand(1, 5)),
                    ]
                );

                $this->upsertHrEmployeeProfile($user, $staff);
            }
        }

        // Support workers (primary test actors)
        $supportRole = Role::query()->where('name', 'support_worker')->first();

        for ($i = 1; $i <= 8; $i++) {
            $w = User::query()->firstOrNew(['email' => "sw{$i}@demo.test"]);
            $w->forceFill([
                'name' => "Support Worker {$i}",
                'password' => $password,
                'role' => 'support_worker',
                'approved_at' => now(),
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            if ($supportRole) {
                $w->roles()->sync([$supportRole->id]);
            }

            // Create staff record for support worker
            $staff = Staff::updateOrCreate(
                ['user_id' => $w->id],
                [
                    'employee_id' => 'SW' . str_pad($i, 3, '0', STR_PAD_LEFT),
                    'job_title' => 'Support Worker',
                    'department' => 'Clinical',
                    'status' => 'active',
                    'hire_date' => now()->subMonths(rand(1, 24)),
                ]
            );

            $this->upsertHrEmployeeProfile($w, $staff);
        }

        // Ensure every staff user has an HR profile, even if the legacy staff
        // record is missing or incomplete.
        User::staff()->with('staffProfile')->get()->each(function (User $staffUser): void {
            /** @var Staff|null $staff */
            $staff = $staffUser->staffProfile;
            $this->upsertHrEmployeeProfile($staffUser, $staff);
        });

        // Create a board member
        $boardUser = User::query()->firstOrNew(['email' => 'board@demo.test']);
        $boardUser->forceFill([
            'name' => 'Demo Board Member',
            'password' => $password,
            'role' => 'board_member',
            'approved_at' => now(),
            'email_verified_at' => now(),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $boardRole = Role::query()->where('name', 'board_member')->first();
        if ($boardRole) {
            $boardUser->roles()->sync([$boardRole->id]);
        }

        $this->command?->info('Created ' . (count($users) + 8 + 1) . ' users with staff records.');
    }

    private function upsertHrEmployeeProfile(User $user, ?Staff $staff): void
    {
        $employeeNumber = $this->employeeNumberFor($user, $staff);
        $positionTitle = trim((string) (($staff?->job_title) ?: $this->defaultJobTitleForRole($user->role)));
        $positionRole = trim((string) ($user->role ?: 'support_worker'));
        $startDate = $staff?->hire_date ? $staff->hire_date->toDateString() : now()->subMonths(6)->toDateString();

        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => self::DEFAULT_TENANT_ID,
                'employee_number' => $employeeNumber,
                'work_email' => $user->email,
                'position_title' => $positionTitle,
                'position_role' => $positionRole,
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'start_date' => $startDate,
                'is_active' => ($staff?->status ?? 'active') !== 'terminated',
                'updated_by' => $user->id,
                'created_by' => $user->id,
            ]
        );
    }

    private function employeeNumberFor(User $user, ?Staff $staff): string
    {
        if ($staff?->employee_id) {
            return trim((string) $staff->employee_id);
        }

        $generated = 'EMP'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT);
        $isOwnedByAnotherProfile = HrEmployeeProfile::query()
            ->withTrashed()
            ->where('employee_number', $generated)
            ->where('user_id', '!=', $user->id)
            ->exists();

        return $isOwnedByAnotherProfile
            ? 'EMP-U'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT)
            : $generated;
    }

    private function defaultJobTitleForRole(?string $role): string
    {
        return match ($role) {
            'admin' => 'System Administrator',
            'provider_manager' => 'Provider Manager',
            'coordinator' => 'Care Coordinator',
            'finance' => 'Finance Officer',
            'hr' => 'HR Manager',
            'auditor' => 'Internal Auditor',
            default => 'Support Worker',
        };
    }
}
