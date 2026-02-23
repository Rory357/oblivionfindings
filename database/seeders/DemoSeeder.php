<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    private const DEFAULT_TENANT_ID = 1;

    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@demo.test'],
            ['name' => 'Demo Admin', 'password' => Hash::make('password'), 'role' => 'admin', 'approved_at' => now()]
        );
        $this->upsertStaffAndHrProfile($admin, 'DEMO-ADM-' . str_pad((string) $admin->id, 3, '0', STR_PAD_LEFT), 'System Administrator', 'IT');

        $manager = User::updateOrCreate(
            ['email' => 'manager@demo.test'],
            ['name' => 'Demo Manager', 'password' => Hash::make('password'), 'role' => 'provider_manager', 'approved_at' => now()]
        );
        $this->upsertStaffAndHrProfile($manager, 'DEMO-PM-' . str_pad((string) $manager->id, 3, '0', STR_PAD_LEFT), 'Provider Manager', 'Operations');

        $workers = collect();
        for ($i = 1; $i <= 6; $i++) {
            $workers->push(User::updateOrCreate(
                ['email' => "sw{$i}@demo.test"],
                [
                    'name' => "Support Worker {$i}",
                    'role' => 'support_worker',
                    'password' => Hash::make('password'),
                    'approved_at' => now(),
                ]
            ));
        }

        foreach ($workers as $worker) {
            $this->upsertStaffAndHrProfile(
                $worker,
                'DEMO-SW-' . str_pad((string) $worker->id, 3, '0', STR_PAD_LEFT),
                'Support Worker',
                'Clinical'
            );
        }

        $clients = Client::factory()->count(10)->create([
            'status' => 'active',
        ]);

        foreach ($clients as $client) {
            $client->supportWorkers()->sync(
                $workers->random(rand(1, 3))->pluck('id')->all()
            );
        }
    }

    private function upsertStaffAndHrProfile(User $user, string $employeeId, string $jobTitle, string $department): void
    {
        $resolvedEmployeeId = $this->resolveUniqueEmployeeId($employeeId, $user->id);

        $staff = Staff::updateOrCreate(
            ['user_id' => $user->id],
            [
                'employee_id' => $resolvedEmployeeId,
                'job_title' => $jobTitle,
                'department' => $department,
                'status' => 'active',
                'hire_date' => now()->subMonths(rand(1, 36)),
            ]
        );

        HrEmployeeProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => self::DEFAULT_TENANT_ID,
                'employee_number' => $staff->employee_id ?: 'EMP' . str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
                'work_email' => $user->email,
                'position_title' => $staff->job_title ?: $jobTitle,
                'position_role' => $user->role ?: 'support_worker',
                'employment_type' => 'full_time',
                'contract_type' => 'permanent',
                'start_date' => $staff->hire_date?->toDateString() ?? now()->subMonths(6)->toDateString(),
                'is_active' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]
        );
    }

    private function resolveUniqueEmployeeId(string $candidate, int $userId): string
    {
        $employeeId = $candidate;
        $suffix = 1;

        while (
            Staff::query()
                ->where('employee_id', $employeeId)
                ->where('user_id', '!=', $userId)
                ->exists()
        ) {
            $employeeId = "{$candidate}-{$suffix}";
            $suffix++;
        }

        return $employeeId;
    }
}
