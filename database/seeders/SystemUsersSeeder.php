<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemUsersSeeder extends Seeder
{
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
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => $password,
                    'role' => $u['role'],
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ]
            );

            $role = Role::query()->where('name', $u['role'])->first();
            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }

            // Create staff record for staff users
            if (!empty($u['staff_data'])) {
                Staff::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'employee_id' => strtoupper(substr($u['role'], 0, 3)) . str_pad($user->id, 3, '0', STR_PAD_LEFT),
                        'job_title' => $u['staff_data']['job_title'],
                        'department' => $u['staff_data']['department'],
                        'status' => 'active',
                        'hire_date' => now()->subYears(rand(1, 5)),
                    ]
                );
            }
        }

        // Support workers (primary test actors)
        $supportRole = Role::query()->where('name', 'support_worker')->first();

        $workers = User::query()->where('email', 'like', 'sw%@demo.test')->get();
        if ($workers->isEmpty()) {
            for ($i = 1; $i <= 8; $i++) {
                $w = User::create([
                    'name' => "Support Worker {$i}",
                    'email' => "sw{$i}@demo.test",
                    'password' => $password,
                    'role' => 'support_worker',
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ]);
                if ($supportRole) {
                    $w->roles()->syncWithoutDetaching([$supportRole->id]);
                }

                // Create staff record for support worker
                Staff::create([
                    'user_id' => $w->id,
                    'employee_id' => 'SW' . str_pad($i, 3, '0', STR_PAD_LEFT),
                    'job_title' => 'Support Worker',
                    'department' => 'Clinical',
                    'status' => 'active',
                    'hire_date' => now()->subMonths(rand(1, 24)),
                ]);
            }
        }

        // Create a board member
        $boardUser = User::updateOrCreate(
            ['email' => 'board@demo.test'],
            [
                'name' => 'Demo Board Member',
                'password' => $password,
                'role' => 'board_member',
                'approved_at' => now(),
                'email_verified_at' => now(),
            ]
        );
        $boardRole = Role::query()->where('name', 'board_member')->first();
        if ($boardRole) {
            $boardUser->roles()->syncWithoutDetaching([$boardRole->id]);
        }

        $this->command->info('Created ' . (count($users) + 8 + 1) . ' users with staff records.');
    }
}
