<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            ['email' => 'admin@demo.test', 'name' => 'Demo Admin', 'role' => 'admin'],
            ['email' => 'manager@demo.test', 'name' => 'Demo Manager', 'role' => 'provider_manager'],
            ['email' => 'coord@demo.test', 'name' => 'Demo Coordinator', 'role' => 'coordinator'],
            ['email' => 'finance@demo.test', 'name' => 'Demo Finance', 'role' => 'finance'],
            ['email' => 'hr@demo.test', 'name' => 'Demo HR', 'role' => 'hr'],
            ['email' => 'auditor@demo.test', 'name' => 'Demo Auditor', 'role' => 'auditor'],
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
            }
        }
    }
}
