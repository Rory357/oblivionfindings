<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CreateTestUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'stephanv48v@gmail.com'],
            [
                'name' => 'Stephan V',
                'password' => Hash::make('Sheila1983@#$!'),
                'email_verified_at' => now(),
                'role' => 'admin',
                'approved_at' => now(),
                'approved_by' => 1,
            ]
        );

        // Attach admin role if roles table exists
        $adminRole = \App\Models\Role::where('name', 'admin')->first();
        if ($adminRole && !$user->roles()->where('role_id', $adminRole->id)->exists()) {
            $user->roles()->attach($adminRole->id);
        }

        $this->command->info('Admin user created/updated: stephanv48v@gmail.com (ID: ' . $user->id . ')');
    }
}
